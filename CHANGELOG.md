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
- Kernel GDT with code and data segments.
- IDT with entry stubs for CPU exceptions 0-31, which report the exception and panic.
