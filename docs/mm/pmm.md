# The physical memory manager

Files: `src/mm/PMM.php`. The selftest is `SelfTest::pmm` in `src/SelfTest.php`.

The PMM keeps track of which pages of RAM are free and hands them out one at a time. A page is
4096 bytes (2^12). The [heap](heap.md) gets its memory from it, and so will page tables and
task stacks.
It works on **physical** addresses, so it only knows about RAM, not about what is mapped where.

## Why a bitmap

There are a few ways to track free pages:

- **A linked list of free pages.** Fast, and needs no extra memory, because each free page can
  hold the link to the next one. But it's hard to see the state of memory, hard to find a run of
  neighbouring pages, and one stray write corrupts the whole list.
- **A buddy allocator.** Good at finding neighbouring pages, but more code and more rules.
- **A bitmap.** One bit per page: 0 is free, 1 is taken. It's small, easy to read and debug, and
  a bit never links to anything, so a bad write only damages that one bit.

We use the bitmap because it's the simplest one that is easy to check by eye. The cost is that
allocating means scanning for a 0 bit. That is fine for now, and the search hint (below) keeps it
quick. It can be replaced later without changing the public methods of `PMM`.

The bitmap uses 1 bit per 4 KiB, so 1 GiB of RAM needs 32 KiB of it.

## The bit maths

Page number `N` covers physical addresses `N * 4096` up to `N * 4096 + 4095`:

```
page number = address >> 12
address     = page number << 12
```

Its bit is at:

```
byte  = N >> 3      (N / 8)
bit   = N & 7       (N % 8)

is it taken?   (bitmap[N >> 3] >> (N & 7)) & 1
mark taken:    bitmap[N >> 3] |=  (1 << (N & 7))
mark free:     bitmap[N >> 3] &= ~(1 << (N & 7))
```

`|=` sets a bit without touching the others. `&= ~mask` clears one: `~` flips the mask so every bit
except ours is 1, and `&` keeps all of those. The code wraps these in `isUsed`, `markUsed` and
`markFree` so the bit twiddling is written once. The bitmap is read and written one byte at a
time with `Mem::read8` and `Mem::write8`. polyc does the maths in 64 bits, and the result is
cut back down to a byte when it is written.

## Starting up: `PMM::init`

`PMM::init` runs once, after the memory map is read and the HHDM offset is set.

1. **How many pages to cover.** Walk the memory map and find the end of the highest *usable*
   region. `totalPages` is that address `>> 12`. Only usable regions count. The framebuffer
   sits near 3 GiB and the last reserved region can be at 1 TiB, so counting them would make
   the bitmap huge for pages we never hand out. Holes in the middle still get bits. They just
   stay taken.
2. **How big the bitmap is.** `bitmapBytes = (totalPages + 7) >> 3` and
   `bitmapPages = (bitmapBytes + 4095) >> 12`. Integer division rounds down, so adding
   "divisor minus 1" first makes it round up. Without that, 9 pages would get 1 byte and the
   ninth page would have no bit.
3. **Where to put it.** The bitmap is memory too, and nothing can allocate yet. So we take the
   first usable region that is big enough ("first fit") and put it at the start of that.
   The base is a physical address, so we add the HHDM offset to get an address we can write
   through. If nothing is big enough we panic.
4. **Start with everything taken.** Fill the bitmap with 0xFF. It is safer to assume every page is
   taken and free only the ones we know are good, than to assume free and try to remember the
   dangerous ones. Anything we forget (holes, reserved memory, the framebuffer) stays safe.
   This is a plain loop. There is no `memset` in the kernel.
5. **Free the usable pages.** For each usable region, clear the bit for every page in it. The
   range is `base >> 12` up to but not including `(base + length) >> 12`. Page 0 is skipped,
   see below. Limine's usable regions are page aligned and don't overlap, so no page is freed
   twice or partly. The bits past `totalPages` in the last byte stay 1, so they are never
   handed out.
6. **Take the bitmap's own pages back out.** Step 5 freed them, because they are inside a usable
   region. If we left them free, `allocPage` would give them away and the next write would
   overwrite the bitmap. This step has to come after step 5, or step 5 would undo it.

Then it prints one line, `PMM: N pages free, bitmap at 0x...`.

### Page 0 is never handed out

`allocPage` returns the physical address of the page, and returns 0 when memory runs out. If
page 0 could be a real answer, a caller couldn't tell a good page from a failure. Page 0 is also
where the old real-mode data lives, so it is a poor choice anyway. It stays taken.

## allocPage

```
for page from searchHint to totalPages:
    if not used: take it, searchHint = page + 1, return page << 12
for page from 0 to searchHint:
    same again
return 0
```

`searchHint` remembers where the last allocation was. Without it every allocation would scan the
full start of the bitmap again, all of which is taken. The second loop covers pages freed behind
the hint. If both loops find nothing, memory is full and the answer is 0.

## freePage

It checks its input before touching anything, and panics if it's wrong:

- **Not a multiple of 4096.** It can't be a page that `allocPage` gave out.
- **Past the end of the bitmap.** Same reason.
- **The page is already free.** This is a double free.

A bad free is a bug in the caller, and the symptom if we ignored it would be two owners sharing
one page, which shows up much later as random corruption. Stopping at the first mistake keeps the
crash next to the cause. When the check passes, it clears the bit, adds one to the free count
and lowers `searchHint` if this page is before it, so the next allocation finds it quickly.

`freePageCount()` returns how many pages are free, for the selftest and for printing.

## Runs of pages: allocContiguous

`allocPage` gives any free page, and nothing promises two calls give neighbours. Something like
the [heap](heap.md) wants one long strip, so `allocContiguous(count)` looks for `count` free pages
in a row:

```
runLength = 0
for page from 0 to totalPages:
    if used: runLength = 0, next page
    if runLength == 0: runStart = page
    runLength++
    if runLength == count: mark them all used, return runStart << 12
return 0
```

Resetting `runLength` on a taken page matters. Without it, two separate free runs would be
treated as one and the caller would get pages that are not neighbours. It leaves `searchHint`
alone, because the hint is only a speed-up for `allocPage`.

`freeContiguous(phys, count)` calls `freePage` for each page. That is slower than clearing the
bits in one go, but it reuses the alignment, range and double-free checks, and it is rarely called.

## The selftest

`SelfTest::pmm` runs at boot after `PMM::init`. It allocates three pages and checks that they are
not 0, are 4096-aligned, are all different, and that the free count dropped by 3. It writes a
64-bit value into one page through the HHDM and reads it back, to prove the page is real RAM.
Then it frees one page and allocates again, which has to return the same page (the search hint
guarantees that), and frees everything and checks the free count is back to where it started.
Any failure panics with a reason, and `make test` fails if the log contains `PANIC`.

The memory test uses `Mem::write64` and `Mem::read64`, which are volatile accesses. Without that,
the compiler could see "write then read the same address" and skip both.

## Checking the numbers

With `qemu -m 256M`:

```
Usable memory: 254 MiB (65166 pages)
PMM: 65164 pages free, bitmap at 0x61000
  page 0x63000
```

The bitmap needs about 8 KiB, which is 2 pages. 65166 minus 2 is 65164. It sits in the first
usable region at `0x61000`, so the first page handed out is `0x63000`, right after it.

## Not done yet

- **Reusing Limine's memory.** The regions Limine is using are not counted as free. They can be
  added once we stop using Limine's page tables and stack.
- **Speed.** Scanning bit by bit is slow on big memory. The easy next step is skipping whole
  bytes that are 0xFF.
- **Locking.** There is only one CPU and no interrupts that allocate, so there is no lock. That
  changes with multitasking.
