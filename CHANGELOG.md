# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `polyc`, a compiler from a typed subset of PHP to C. The kernel is written in PHP, and `make`
  compiles it with polyc. The compiler has its own tests (`make test-compiler`).
- Boot on the Limine bootloader (v11) with base revision 3.
- COM1 serial driver (polled, 115200 8N1).
- `kprintf` with `%c %s %d %u %x %p %%` and the `l` and `ll` length modifiers, plus a boot-time
  selftest.
- `panic()`, which prints a message and halts.
- Kernel GDT with code and data segments, and a TSS.
- IDT with entry stubs for CPU exceptions 0-31, which report the exception and panic.
- Double faults run on their own stack (IST), so a broken stack no longer causes a silent triple
  fault.
- Request the memory map and HHDM offset from Limine and print them at boot.
- Physical page allocator (bitmap), with a boot-time selftest.
- Kernel heap (`kmalloc` and `kfree`, first fit with splitting and merging), with a boot-time
  selftest.

### Changed

- In the kernel's PHP subset, `/` and `/=` now divide whole numbers (rounding toward zero), so
  `intdiv($a, $b)` is no longer needed. `intdiv` still works.
