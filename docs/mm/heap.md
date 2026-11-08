# The kernel heap

Files: `src/mm/Heap.php`, `src/mm/Block.php`. The selftest is `SelfTest::heap` in `src/SelfTest.php`.

The [page allocator](pmm.md) only hands out whole 4 KiB pages. Most things the kernel needs are much
smaller: a 20 byte name, a 100 byte list entry. Giving each of those a whole page would waste
almost all of it. The heap fixes that with `Heap::kmalloc($n)`, which gives you `$n` bytes, and
`Heap::kfree($address)`, which gives them back. Both work with addresses, which are plain integers
(`u64`). `kmalloc` returns 0 when it can't give you the memory.

## The idea

We buy one big strip of memory from the page allocator, once, at boot. We cut it into **boxes** of
whatever size people ask for. Every box has a **label** stuck on the front of it. The label says
how big the box is, whether it is free, and where the boxes on either side are.

```
[label][ box ][label][   box   ][label][box][label][ ...free... ]
```

The label is a `Block`:

```php
#[Size(32)]
final class Block
{
    public u64 $size; // bytes of space after the label (not counting the label)
    public ?Block $next; // the box after this one, or null
    public ?Block $prev; // the box before this one, or null
    public u32 $magic; // MAGIC_USED or MAGIC_FREE
    public u32 $isFree; // 1 if the box is free
}
```

A class with properties is a struct, and an object is the address of one. `Block::at($address)`
turns an address into a `Block` you can read and write, and `?Block` means "a `Block` or `null`".

## Why it is built this way

**A fixed strip from `allocContiguous`.** The page allocator gives out single pages, and nothing
promises they are next to each other. A heap wants one long run of memory. So `PMM` has
`allocContiguous($count)`, which finds `count` free pages in a row. RAM is mapped in order by the
HHDM, so pages that are neighbours physically are neighbours in memory too, and the heap can
treat the strip as one big array of bytes. The heap is 256 pages, which is 1 MiB.

**A label in front of each box.** There are other ways to track boxes, such as a separate table.
A label in front is the simplest. Given an address from `kmalloc`, the label is always exactly
one `Block` before it, so `kfree` needs no search to find it.

**Two links, `next` and `prev`.** With only `next`, `kfree` would have to walk the whole list to find
the box before it. With both, merging with either neighbour is just a few pointer changes.

**First fit.** `kmalloc` takes the first free box that is big enough. Best fit (the smallest box
that works) wastes less space, but has to look at every box every time. First fit is simple and
good enough.

**The label is 32 bytes.** 8 + 8 + 8 + 4 + 4 is 32, and `#[Size(32)]` makes the compiler check it. The space starts
right after the label, so a label that is a multiple of 16 bytes means the space is 16-byte
aligned. The x86-64 rules expect memory from `malloc` to be 16-byte aligned, and compiled code
may rely on it.

**Everything is a multiple of 16.** `roundUp16($n)` is `($n + 15) & ~15`. Adding 15 pushes any
number past the next multiple of 16, and `& ~15` clears the low 4 bits, which rounds down to that
multiple. Together that rounds up. If every box size is a multiple of 16 and the first label is
aligned, every label and every address stays aligned.

## Heap::init

1. Ask for 256 contiguous pages. If that fails, panic.
2. The address is physical, so add the HHDM offset to get an address we can use.
3. Remember `$end`, one byte past the strip. `kfree` uses it to check addresses.
4. Write one label at the start: one giant free box, whose size is the whole strip minus the 32
   bytes of the label itself. That is the entire starting state.

## kmalloc

```
if size is 0, return 0
round size up to a multiple of 16
for each box, from the start:
    skip it if it is used or too small
    if the leftover (box size - size) is at least 32 + 16:
        cut the box in two: the second part is a new free box with its own label
    mark the box used, return the address just after the label
return 0
```

The **split** is the interesting part. The new label goes right after the bytes we keep:

```php
$rest = Block::at(Mem::addr($b) + Block::SIZE + $size);
```

`Mem::addr($b) + Block::SIZE` is the first byte of the space, one whole label after `$b`. Adding
`$size` bytes to that gives the end of the part we keep. The new box's size is the leftover minus its own label.
Then we link it in between `b` and whatever came after `b`.

**Why the leftover must be at least 48 bytes.** A new box needs a 32-byte label and at least 16 bytes
of space. A smaller leftover would be a box too small to ever be used, so we don't cut and the
caller gets a box slightly bigger than they asked for.

**Addresses are plain numbers.** `Mem::addr($b)` is the address of the `Block` as an integer, so
adding to it always counts bytes, never whole objects. `Block::SIZE` is the size of a label (32).
That is much harder to get wrong than C pointer arithmetic, where `b + 1` is a whole label later.

## kfree

1. Address 0 does nothing, like `free(NULL)` in C.
2. Check the address is inside the heap (after the first label, before `$end`).
3. Step back one `Block` to the label and check `magic == MAGIC_USED`. A box that is already free
   has `MAGIC_FREE`, so freeing the same address twice panics. An address in the middle
   of a box, or memory that was overwritten, almost certainly won't hold the stamp either.
4. Mark the box free.
5. If the next box is free, absorb it: our size grows by its size **plus its label**.
6. If the previous box is free, it absorbs us the same way.

The forward merge comes first. After it, `b` is as big as it will get, so the backward merge only
has to absorb one box.

**Why merge at all.** Without merging, allocating lots of small things and freeing them all would
leave a strip full of tiny free boxes. A big request would then fail even though plenty of memory
was free in total. This is called fragmentation. Merging puts the neighbours back together.

**Why panic on a bad address.** A bad free means the kernel is broken. If we ignored it, the heap
could end up corrupted and crash much later somewhere unrelated. Stopping at the first mistake
keeps the crash close to the cause.

## One example

Say the strip starts at `0x1000`. Real addresses are higher, but the offsets are what matter.

Start: one box. Label at `0x1000`, space from `0x1020`, size `0xFFFE0`.

`a = kmalloc(1)`, `b = kmalloc(100)` and `c = kmalloc(5000)` round up to 16, 112 and 5008 bytes:

```
0x1000 label    0x1020 a: 16 bytes
0x1030 label    0x1050 b: 112 bytes
0x10C0 label    0x10E0 c: 5008 bytes
0x2470 label    the rest, still free
```

The boot log shows exactly these offsets (`a=...20 b=...50 c=...e0`).

`kfree(b)` marks b's box free. Its neighbours are both in use, so nothing merges.

`kmalloc(50)` rounds to 64. The first free box that fits is b's old box. The leftover is 112 - 64 = 48,
exactly enough, so it splits into a 64-byte box and a free 16-byte box. The caller gets `0x1050`, which
is `b` again.

`kfree` that block. Its next neighbour (the 16-byte box) is free, so they merge: 64 + 32 + 16 =
112, the original box again.

Free `a`: its next box is free, so they merge. Free `c`: its next box (the rest) is free and its
previous box is free, so both merge. Everything is one box again.

## The selftest

`SelfTest::heap` runs after `Heap::init`. It checks that:

- `kmalloc(0)` gives 0.
- Three blocks (1, 100 and 5000 bytes) are not null, are 16-byte aligned and drop the free total.
- Each block, filled with its own byte, is still intact after the others are filled. Overlapping
  blocks would show up here.
- After freeing `b`, a 50-byte request lands in the same place.
- After freeing everything, `freeBytes()` is back to where it started. That only works if
  merging worked, because the free total counts the space in the boxes, not their labels.
- A 64 MiB request gives 0.

A failure panics with the reason, and `make test` fails if the log contains `PANIC`.

## Not done yet

- **The heap can't grow.** It is fixed at 1 MiB and returns 0 when full. Growing means
  asking the page allocator for another strip and linking it in. Merging would then have to check
  the two boxes are really next to each other.
- **Allocation is a linear walk.** `kmalloc` can look at every box. Free lists sorted by size fix
  that, at the cost of more code.
- **A buffer overflow ruins the next label.** Writing past the end of a block overwrites the
  neighbour's label. `kfree` can catch it through the magic stamp, but only when it is later
  freed. A guard word after each block would catch it sooner.
- **No `new` and `delete` in the kernel.** polyc can build `new Block()` on top of an allocator it is
  given (the `--allocator` option, for example `Polyvanthos\Mm\Heap::kmalloc`), but the kernel does
  not use `new` yet and there is no `delete`. An object would be given back with an explicit
  `Heap::kfree(Mem::addr($object))`.
- **No zeroing.** Memory from `kmalloc` holds whatever was there before. A `kzalloc` that clears it
  would be easy to add.
- **No locking.** There is one CPU and nothing that allocates from an interrupt handler. That
  changes with multitasking.
