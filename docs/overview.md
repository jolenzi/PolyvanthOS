# Overview

PolyvanthOS is a small 64-bit operating system for x86_64 PCs. It is early. Today it boots, prints
text over a serial port, and when the CPU hits an error it prints a report that says what went
wrong and where. It has no memory management, no scheduler, no filesystem and no keyboard yet.

This page shows how the pieces fit together. The other pages go through each piece in detail.

## What happens when it boots

1. The firmware (BIOS or UEFI) starts Limine from the ISO.
2. Limine reads `limine.conf`, finds `/boot/kernel` and loads it into memory.
3. Limine puts the CPU in 64-bit mode, turns on paging, and jumps to `kmain`, which is the C
   name of `Kernel::main`.
4. `Kernel::main` sets up the serial port, so from here on we can print.
5. It checks that Limine accepted the protocol revision we asked for.
6. It loads our own GDT, then our own IDT.
7. It runs the `kprintf` selftest, then stops the CPU.

All output goes to the serial port (COM1), not to the screen. In QEMU that shows up in your
terminal.

## Where things are

```
src/
  Kernel.php                entry point, the boot sequence above
  SelfTest.php              boot-time checks for kprintf
  Mem.php                   read and write memory at an address
  Cast.php                  explicit conversions between number sizes
  arch/x86_64/
    Port.php                in/out instructions (outb, inb)
    CPU.php                 haltForever() and the other CPU instructions
    GDT.php                 segment table
    TablePointer.php        what lgdt and lidt read
    IDT.php                 interrupt table and the exception handler
    IDTEntry.php            one slot of the interrupt table
    InterruptFrame.php      the registers saved by ISR.S
    ISR.php                 the table of assembly stubs
    asm/ISR.S               assembly entry points for exceptions
    linker.ld               memory layout of the kernel
  boot/
    Limine.php              the numbers of the Limine boot protocol
  drivers/
    Serial.php              COM1 serial port
  lib/
    Kprintf.php             formatted printing
    Panic.php               stop the kernel with a message
native/                     the few lines of C that PHP can't express (asm, memory access)
tools/polyc/                the PHP-to-C compiler, with its tests
limine.conf                 bootloader config
Makefile                    build, run, check and test
docs/                       these pages, laid out like src/
  images/                   logo and social preview
```

## Rules the code follows

**No heap.** There is no memory allocator yet. Everything is either a global variable or lives on
the stack. That is why buffers like the one in `putUint` have a fixed size.

**No startup code.** Nothing runs before `kmain`, and the linker script does not run the
`.init_array` section. So a static property can only start out as a plain constant, which the
compiler writes into the file. anything else fails to build. That is also why `GDT::init` fills in
its `TablePointer` when it runs: the address of the table isn't a constant polyc can write into
a declaration.

**PHP, compiled to C.** The kernel is written in a typed subset of PHP. `tools/polyc` compiles it
to C, and clang builds that C for bare metal. There is no PHP at run time, no interpreter and no
garbage collector: classes are structs, static methods are C functions, and objects are
pointers. The only C in the repository is the handful of functions in `native/` that PHP can't
express, such as the CPU instructions. See [the language](language.md) and
[the compiler](tools/polyc.md).

**Architecture code stays under `src/arch/`.** Port I/O, the GDT, the IDT and the halt
instruction are x86 specific, and other code calls them through small classes. The goal is to
port to ARM later without touching the rest. To be honest about where we are: the serial driver
uses x86 port I/O too, so it will need a sibling driver on other platforms.

**Bugs that can only be our fault cause a panic.** If `putUint` gets a base of 1, no caller can
mean that, so it calls `Panic::now()` instead of guessing.

## What it can't do yet

- Exceptions are fatal. The handler prints a report and stops. It can't recover from, say, a page
  fault.
- Only CPU exceptions (vectors 0 to 31) are set up. There are no hardware interrupts, so no timer
  and no keyboard.
- There is one CPU, one stack, and no TSS. A stack overflow ends in a triple fault, which resets
  the machine without a message.
- Serial output is polled, and a character can be dropped if the port never becomes ready.
- `kprintf` has no field widths or zero padding.
- The only automated test is the boot selftest.

## What comes next

Read the memory map from Limine, build a physical page allocator, set up paging, add a heap, then
a timer interrupt and a scheduler. Multitasking sits on top of all of that.
