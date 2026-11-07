# CPU helpers

Files: `src/arch/x86_64/Port.php`, `src/arch/x86_64/CPU.php`, `native/x86_64.h`.

Two small pieces of x86 specific code that the rest of the kernel uses.

PHP has no way to say "run this CPU instruction". So the instructions themselves are short C
functions in `native/x86_64.h`, and a PHP class declares them with `#[Native]` so that other
code can call them like any other method. The compiler checks the calls and writes them out. See
[the compiler](../../tools/polyc.md) for how that works.

## Port I/O

Most hardware is reached through memory addresses, but x86 also has a second, separate address
space called I/O ports. You can't reach it with a normal pointer. The CPU has two special
instructions for it, `in` and `out`, and `native/x86_64.h` wraps them. The `Port` class
declares them as `Port::outb` and `Port::inb`:

```c
__asm__ volatile("outb %0, %1" : : "a"(value), "Nd"(port) : "memory");
```

The `"a"` means "put `value` in the `al` register", which is where `out` wants the byte. The `"Nd"`
means the port goes in `dx`, or is written as a constant if it fits in 8 bits. `volatile` stops
the compiler from removing or reordering the instruction, and `"memory"` tells it that the
instruction might have side effects it can't see.

Both functions are `static inline` and live in a header, so each use compiles down to the
single instruction.

On ARM there is no separate port space, devices are all memory mapped. A port of this kernel would
provide different code behind the same kind of small interface.

## haltForever

`CPU::haltForever()` is ordinary PHP. It calls two of the native instructions, `CPU::cli()` and
`CPU::hlt()`, in this order:

```
cli
loop: hlt
```

`cli` clears the interrupt flag, so the CPU ignores maskable interrupts. Without it, an interrupt
could wake the CPU and run more code after the panic, which is what we're trying to prevent.

`hlt` puts the CPU to sleep until the next interrupt. It is much better than spinning in an
empty loop, because the CPU stops burning power and the emulator stops using a full core.

Even with `cli`, `hlt` can still wake up. A non-maskable interrupt (NMI) ignores the interrupt
flag, and so do a few other events. So `hlt` sits inside a loop and goes back to sleep every
time. The function's return type is `never`, which tells the compiler it never comes back.
polyc turns that into a C `noreturn` function and rejects any `return` inside it.

The other instructions in `CPU` (loading the GDT and IDT, reloading the segment registers,
reading `cr2`) are explained where they are used, in the [GDT](gdt.md) and
[interrupts](idt.md) pages.

