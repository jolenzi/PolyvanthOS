# The memory map

Files: `src/mm/MemMap.php`, `src/boot/MemMapResponse.php`, `src/boot/MemMapEntry.php`,
`src/boot/HHDMResponse.php`. The requests live in `src/Kernel.php`.

Before the kernel can hand out memory, it has to know what memory there is. The firmware knows,
and Limine collected the answer before it started us. We ask Limine for two things: the memory
map, and the HHDM offset.

## Asking Limine

The kernel can't call Limine at runtime. Instead we leave requests in our own memory, and Limine
fills in the answers before it jumps to `kmain`. Each request is an array of 64-bit words, and
its last word is the `response`. If Limine understood the request, `response` is the address of
the answer. If not, it stays 0, so `Kernel::main` checks both and panics if either is 0.
Without the map there is nothing safe to do next. See [boot/limine.md](../boot/limine.md) for how requests are found.

## The memory map

The map is a list of regions. Each one has a start address, a length and a type:

```php
final class MemMapEntry
{
    public u64 $base;
    public u64 $length;
    public u64 $type;
}
```

A class with properties is a struct, and an object is the address of one. `MemMapEntry::at($address)`
turns an address into an object you can read fields from.

`entries` in the response is the address of an array of pointers to these, and `entryCount` says
how long it is. That is why `MemMapResponse::entry($i)` does two steps: read the pointer out of
the array with `Mem::read64`, then turn it into a `MemMapEntry` with `MemMapEntry::at`.

The types, and the name each one is printed as:

| Printed as | Meaning |
|---|---|
| free | Usable RAM. The only memory we may hand out. |
| reserved | Used by hardware or firmware. Never touch it. |
| ACPI tables | ACPI tables. Free to reuse once we've read them. |
| ACPI NVS | ACPI memory that must be preserved. |
| bad RAM | Broken RAM. |
| Limine (in use) | Memory Limine is using right now (its data, and the page tables we are running on). Free to reuse once we've stopped needing it. |
| kernel + modules | Our own code and data. |
| framebuffer | The screen. |
| reserved (mapped) | Reserved, but Limine has it mapped. |

`typeName()` turns the type number into one of these names so the log is readable. The names
are in an array, and the type number is the index, because Limine numbers its types 0 to 8 with
no gaps. `#[Count(9)]` on the array makes the compiler check that it has one entry per type, so if the
list ever grows the build fails instead of printing the wrong name. A type number past the end of the
array prints as "unknown", so a type added in a newer Limine shows up instead of being
misreported.

## Printing it

`printMemoryMap` walks the list and prints one line per region as `[start - end)`. The end is
`base + length`, and the range is half open, so `end` is the first address *after* the region.
Sizes are shifted right by 10 to show KiB. A shift right by N divides by 2^N, which is how
kernels usually do it.

While it loops it adds up the length of every usable (`free`) region. At the end it prints that
total in MiB (`>> 20`) and in pages (`>> 12`, because a page is 4096 = 2^12 bytes). The page
count is the number the page allocator will start from.

Example, with `qemu -m 256M`:

```
[0x1000 - 0x61000) 384 KiB  Limine (in use)
[0x61000 - 0x9f000) 248 KiB  free
[0x9fc00 - 0xa0000) 1 KiB  reserved
[0xf0000 - 0x100000) 64 KiB  reserved
[0x100000 - 0xff33000) 260300 KiB  free
[0xff33000 - 0xff35000) 8 KiB  Limine (in use)
[0xff35000 - 0xff3d000) 32 KiB  kernel + modules
...
Usable memory: 254 MiB (65169 pages)
```

Things worth noticing:

- The map is not a single block of RAM. There are holes, and the first MiB is full of reserved
  and legacy areas (the BIOS area at 0xF0000, for example).
- Free memory doesn't start at 0. Page 0 is never in a usable region.
- The total is a bit under 256 MiB. The rest is reserved, kernel, and Limine's own memory.
  "Limine (in use)" is not counted as usable yet, because we are still running on
  Limine's page tables and stack. It can be added back later.
- Regions come in ascending address order and don't overlap. The allocator can rely on that.

## The HHDM offset

HHDM stands for "higher half direct map". Paging is on, so the kernel can only touch addresses
that are mapped. Limine maps all of physical RAM a second time, starting at one fixed virtual
address (the offset), so physical address `P` is reachable at `P + offset`.

With `-m 256M` the offset prints as `0xffff800000000000`. The kernel image itself is somewhere
else (`0xffffffff80000000`); the HHDM is just a window onto RAM. Anything that has to read or
write a physical page, such as a page table or the allocator's own bookkeeping, will do it
through `P + offset`.

`Kernel::main` saves the offset with `MemMap::setHhdmOffset()` and the rest of the kernel reads it
with `MemMap::hhdmOffset()`. It is a private static property that is written once at boot,
before anything else needs it.

## Not done yet

- The map is only used to set up the [page allocator](pmm.md). Nothing else reads it.
- The kernel image's own page tables are still Limine's.
- Limine's memory and the ACPI tables are never reused.
