# Booting with Limine

Files: `limine.conf`, `src/boot/Limine.php`, and the request markers at the top of `src/Kernel.php`.

## Why use a bootloader

When a PC starts, the CPU is in a 1980s mode (16-bit real mode) with no memory protection. Getting
from there to 64-bit mode with paging takes a lot of fiddly code that has nothing to do with what
makes this OS different. Limine does it for us and hands over a CPU that is ready to run 64-bit
code. It also works on both BIOS and UEFI machines, and it can tell us about the memory map and
the framebuffer later.

## What Limine gives us on entry

When Limine jumps to `kmain` (the C name of `Kernel::main`):

- The CPU is in 64-bit mode with paging on. Interrupts are off.
- The kernel is mapped at the address it was linked for.
- All of physical memory is mapped at a high offset (the "higher half direct map", or HHDM).
- There is a small stack, but we didn't pick it. We'll want our own later.
- Limine's own GDT is loaded. Its code and data selectors are 0x28 and 0x30 (we printed them
  before loading ours).

## limine.conf

```
timeout: 0

/PolyvanthOS
    protocol: limine
    path: boot():/boot/kernel
```

`timeout: 0` boots the first entry right away, with no menu. `protocol: limine` says the kernel
speaks Limine's own protocol, as opposed to Multiboot. `boot():/boot/kernel` means "the file
`/boot/kernel` on the same disk the bootloader came from".

## The request markers

Limine finds out what the kernel wants by scanning the kernel's memory for special 64-bit
patterns. `Kernel.php` has three static arrays for this:

```php
Kernel::$requestsStart   // start marker
Kernel::$baseRevision    // "I want protocol revision 3"
Kernel::$requestsEnd     // end marker
```

The numbers themselves are constants in the `Limine` class (`src/boot/Limine.php`). They come from
the Limine boot protocol, and both sides have to agree on them.

Each one is placed in its own section with `#[Section('...')]` (`.limine_requests_start`,
`.limine_requests`, `.limine_requests_end`), and the [linker script](../arch/x86_64/linker.md) puts those three
sections next to each other at the start of `.data`, in that order. Limine looks between the start and end markers for requests.

`#[Used]` stops the compiler from deleting arrays that nothing in the code reads, and
`#[Volatile]` tells it Limine might write to them. `KEEP(...)` in the linker script does the same
job for the linker. The compiler turns these attributes into the matching C ones. See
[the language reference](../language.md).

### Asking for more than the revision

Besides the base revision, `Kernel.php` has two real requests: one for the memory map and one
for the HHDM offset. Each is an array of six 64-bit words: an `id` of four words (Limine's magic
numbers for that request), a `revision` (0), and a `response` word that starts as 0. Before
`Kernel::main` runs, Limine finds the array and puts the address of its answer in the last word.
`Kernel::main` reads it with `Limine::RESPONSE_WORD` (word 5) and checks it isn't 0 before using
it. They sit in `.limine_requests` between the markers like the others. See
[../mm/memmap.md](../mm/memmap.md) for what we do with the answers.

The arrays are `#[Volatile]` for the same reason as before: our code never writes the response
word, so without `volatile` the compiler could decide it is always 0 and delete the check.

### The base revision check

Limine marks the revision as supported by changing the third value in the array from our revision
number to 0. `Kernel::main` checks that `$baseRevision[2]` is 0. If it's wrong, we panic,
since the rest of the boot would be running on assumptions that don't hold.

This is a lesson from an early version. The base revision array used to sit at the very end of
the data with nothing after it. Limine only reads positions that have at least 32 bytes left in
the image, so it never saw our tag and quietly ran the kernel as revision 0, the oldest
behaviour. Nothing complained until we added the check. Wrapping the tag in the start and end
markers, as described above, fixed it.

One more note for later: Limine's source requires a base revision of at least 6 on aarch64, so
revision 3 will not boot on ARM. We'll bump it when we get there.
