# Linker script

File: `src/arch/x86_64/linker.ld`.

The linker script tells the linker where to put things in the kernel file and in memory.

```
ENTRY(kmain)
. = 0xFFFFFFFF80000000;
```

`ENTRY(kmain)` is the address Limine jumps to. The kernel starts at `0xFFFFFFFF80000000`, which is
the very top 2 GB of the 64-bit address space. It's called the higher half because user programs
will get the lower half of the address space later. It also matches `-mcmodel=kernel`: any
address in that range can be reached with a 32-bit signed offset, so the compiler can generate
smaller and faster code.

The sections come in this order: `.text` (code), `.rodata` (constants), `.data` (variables with
starting values) and `.bss` (variables that start at zero). Between them the script aligns to
4 KiB, so each group starts on its own page. That lets us make code read-only and executable,
constants read-only, and data writable once we manage page permissions ourselves. The linked
kernel has three loadable segments for exactly this reason: one for code, one for read-only data,
and one for data and bss.

## The Limine sections

The three request sections (`.limine_requests_start`, `.limine_requests`, `.limine_requests_end`)
are placed first inside `.data`, in that order, and wrapped in `KEEP(...)`. Limine scans for its
markers in that order, so they have to sit together. `KEEP` stops the linker from throwing the
sections away, since nothing in the code refers to them by name. See [Booting with
Limine](../../boot/limine.md) for what the markers are for.
