# Panic and halt

Files: `src/lib/Panic.php`. It calls `CPU::haltForever()` from `src/arch/x86_64/CPU.php`.

A panic is the kernel saying "something is wrong and I can't carry on safely". It prints one line
and stops the CPU. Continuing after a kernel bug is how you get corrupted data that shows up
hours later somewhere unrelated, so stopping early is the kinder choice.

## Panic::now(message)

```
if we are already panicking, just halt
remember that we are panicking
print "PANIC: <message>"
halt
```

### The "already panicking" flag

`Panic::now` prints, and printing can go wrong. If something inside `Kprintf::printf` ended
up calling it again, we would print again, fail again, and loop until the stack ran out. A flag turns
the second call into a plain halt. It's a `private static bool` property. Its starting value
is a constant, so the compiler writes it into the file and nothing has to run at startup.

### Why the message goes through "%s"

The code is `Kprintf::printf("PANIC: %s\n", $message)`, not `Kprintf::printf($message)`. If a message ever contained
a `%` sign, using it as the format would try to read arguments that aren't there. Passing it as
an argument to `%s` prints it as it is.

### When it's safe to call

`Panic::now` prints through the serial port, so it only works after `Serial::init`. A panic before
that would try to print to an unconfigured port. The base revision check in `Kernel::main` comes
after `Serial::init` for that reason. For the same reason `Serial::init` itself can't panic on a
bad baud rate.

## haltForever()

The last step of a panic is `CPU::haltForever()`, which stops the CPU for good. It lives in the
x86 code and is explained in [CPU helpers](../arch/x86_64/cpu.md#haltforever).

## Where it's used

- Invalid base in `putUint`.
- Limine rejecting our base revision.
- The end of an unrecoverable CPU exception, after the register dump.
- `Kernel::main` ends with `CPU::haltForever()`, because there's nothing else to do yet.
