# PolyvanthOS documentation

These pages explain how the code works and, more than that, why it was written the way it was.
The folders match the source tree, so the page for `src/lib/Kprintf.php` is
`docs/lib/kprintf.md`. If you are new to the project, read the overview first.

## General

- [Overview](overview.md): what the kernel does today, how a boot goes, the source layout, and
  the rules the code follows.
- [The language](language.md): the subset of PHP the kernel is written in, and what each part of it
  turns into in C.
- [The compiler](tools/polyc.md): how polyc turns PHP into C, how to use it, and how it is tested.
- [Building and testing](build.md): the Makefile, what each compiler flag is for, the ISO, and
  how to debug a crash.

## Pages that match `src/`

| Source | Page | What it covers |
|---|---|---|
| `limine.conf`, `src/boot/`, `src/Kernel.php` | [boot/limine.md](boot/limine.md) | How the kernel gets loaded, what Limine hands over, the request markers, and the base revision check |
| `src/Mem.php`, `src/Cast.php`, `native/mem.h` | [mem.md](mem.md) | Reading and writing memory at an address, and converting between number sizes |
| `src/arch/x86_64/linker.ld` | [arch/x86_64/linker.md](arch/x86_64/linker.md) | Where the kernel sits in memory and how the sections are laid out |
| `src/arch/x86_64/Port.php`, `CPU.php`, `native/x86_64.h` | [arch/x86_64/cpu.md](arch/x86_64/cpu.md) | Port I/O and halting the CPU |
| `src/arch/x86_64/GDT.php`, `TSS.php` | [arch/x86_64/gdt.md](arch/x86_64/gdt.md) | The segment table and why we load our own |
| `src/arch/x86_64/IDT.php`, `src/arch/x86_64/asm/ISR.S` | [arch/x86_64/idt.md](arch/x86_64/idt.md) | The interrupt table, the assembly stubs, and the crash report |
| `src/drivers/Serial.php` | [drivers/serial.md](drivers/serial.md) | Talking to the UART at COM1 |
| `src/lib/Kprintf.php` | [lib/kprintf.md](lib/kprintf.md) | Number printing, format strings, and variable arguments |
| `src/lib/Panic.php` | [lib/panic.md](lib/panic.md) | What happens when the kernel gives up |
| `src/mm/MemMap.php` | [mm/memmap.md](mm/memmap.md) | Asking Limine for the memory map and the HHDM offset, and what the map tells us |
| `src/mm/PMM.php` | [mm/pmm.md](mm/pmm.md) | The page allocator: the bitmap, how it starts up, and why it is built this way |
| `src/mm/Heap.php` | [mm/heap.md](mm/heap.md) | `kmalloc` and `kfree`: boxes with labels, splitting, and merging |

## Images

The logo (SVG and PNG sizes) and the GitHub social preview image are in [images/](images/). The
SVG files are the sources. The PNGs are rendered from them.

The code is the final word. If a page and the code disagree, the page is out of date, and a fix
to the page is welcome.
