# polyc, the PHP-to-C compiler

Files: `tools/polyc/polyc`, `tools/polyc/src/*.php`, `tools/polyc/tests/`.

`polyc` reads the kernel's PHP files and writes C. The Makefile runs it first, then compiles the C
with clang as if it had been written by hand. The compiler itself is plain PHP and runs on your
computer, not in the kernel. You need `php-cli` (PHP 8.1 or newer, with the `tokenizer` extension,
which Ubuntu's package has).

## Why a compiler and not an interpreter

There is no operating system under the kernel, so there is nothing to run PHP on. Writing the
kernel in PHP therefore means compiling it. Compiling to C, not to machine code, buys three things:
clang does the optimising and the code generation, the output is easy to read and debug, and the
few places that need CPU instructions can be a line of inline asm instead of a new compiler
feature.

## Using it

```
php tools/polyc/polyc --psr4 Polyvanthos=src --out build/gen  src/Kernel.php src/SelfTest.php ...
```

| Option | Meaning |
|---|---|
| `--psr4 Prefix=dir` | Classes under this namespace are in this folder. Can be given more than once |
| `--allocator Class::method` | The static method that `new` calls to get memory (size in, address out). Optional: without it `new` is an error |
| `--out dir` | Where to write the C |
| the rest | PHP files to compile. Classes they use are found through `--psr4` and compiled too |

It writes:

- `polyc.h`: everything shared. The struct definitions, the constants as `#define`s, tables, the
  prototypes of public functions, and the `#include`s for the native headers.
- One `.c` file for each PHP file, at the same path: `src/drivers/Serial.php` becomes `drivers/Serial.c`. Each
  includes `polyc.h`.

A file is only rewritten when its contents change, so `make` rebuilds exactly what changed.

On an error it prints `file:line: error: message` and exits with 1.

## How it works

```
 PHP file ──> tokens ──> tree ──> classes ──> C
            PhpToken    Parser   Program     CodeGen + FunctionGen
```

1. **Tokens.** PHP's own tokenizer (`PhpToken::tokenize`, from the `tokenizer` extension) splits
   the file the way PHP would. This is why the source can be real PHP: strings, comments, numbers
   and attributes are read by the same code that reads any PHP file.
2. **`Parser`.** A hand-written parser turns the tokens into a tree of plain arrays. It only
   accepts the subset described in [the language](../language.md). Anything else is an error with a
   line number: it would be worse to accept a feature and quietly build it wrong.
3. **`Program`.** Collects the classes: their constants, properties and methods with their types,
   and their attributes. It finds classes that the code refers to by looking in the folder that
   matches the namespace, so a class is loaded the first time something uses it.
4. **`FunctionGen`.** Compiles one method body. It knows the type of every expression, which is how
   it applies the number rules, checks calls, finds the type of each variable, and decides where
   a cast is needed. It writes C.
5. **`CodeGen`.** Runs `FunctionGen` for every method of every class, then assembles the header and
   the `.c` files: struct layouts, constants, static properties, function prototypes.

The whole program is compiled in one run, because a class can refer to any other. That is also
why there are no header files to write: the header is generated.

## What the output looks like

For `Serial::putc`:

```php
public static function putc(u8 $c): void
{
    $timeout = self::TX_TIMEOUT;
    while ((Port::inb(self::COM1_BASE + self::UART_LSR) & self::LSR_TX_EMPTY) === 0 && --$timeout > 0) {
    }
    if ($timeout > 0) {
        Port::outb(self::COM1_BASE + self::UART_DATA, $c);
    }
}
```

becomes roughly:

```c
void Drivers_Serial_putc(uint8_t c)
{
    int64_t timeout = 0;
    timeout = 100000L;
    while ((((int64_t)Arch_X86_64_Port_inb((uint16_t)(1016L + 5L)) & 32L) == 0L) && ((--timeout) > 0L)) {
    }
    if (timeout > 0L) {
        Arch_X86_64_Port_outb((uint16_t)(1016L + 0L), c);
    }
}
```

It is not pretty, but it is plain C that does what the PHP says. Constants have been replaced by
their values (`0x3F8 + 5` is `1016L + 5L`), and every conversion has its cast written out, which
is what keeps C's conversion rules out of the picture.

## Native code

Anything that needs a CPU instruction is written as a C function in a header under `native/`, and
a PHP class describes it so the compiler can check calls to it. The C name is what the compiler
would generate: `Arch_X86_64_Port_outb` for `Polyvanthos\Arch\X86_64\Port::outb`.

To add an instruction:

1. Write `static inline void Arch_X86_64_CPU_something(...)` in `native/x86_64.h`.
2. In `src/arch/x86_64/CPU.php`, add `#[Native] public static function something(...): ...` with an
   empty body and the same parameter and return types.

For another architecture, write the same function names in another header and name it in the
`#[Native('...')]` or `#[Header('...')]` attribute of those classes.

## Testing the compiler

`make test-compiler` runs `tools/polyc/tests/run.php`. It does two kinds of check:

- **`tests/cases/`.** Each file with `// expect:` lines is compiled, built with the computer's C
  compiler under `-Wall -Wextra -Werror`, and run. The output must match the `// expect:` lines.
  This tests the generated code for real: the number rules, `switch`, structs, `new`, variadic
  calls and the rest. The helper classes `Host`, `Mem` and `Counter` live there too. `Host`
  prints through `tests/native/host.h`.
- **`tests/errors/`.** Each file must be rejected, and the message must contain the text in its
  `// error:` line. These keep the compiler from quietly accepting something it shouldn't.

The kernel is the largest test. `make test` boots it in QEMU and checks its selftests.

## Limits

- One class per file. No inheritance, interfaces or traits.
- No floats, no arrays that grow, no string joining.
- It does not check that the arguments of `Kprintf::printf` match the format string.
- Types are checked, but there is no flow analysis: `?IDTEntry` can be passed where `IDTEntry` is
  expected, the way a C pointer can be null, so a `null` there is on you.
- The error messages say which line, but not which column.
