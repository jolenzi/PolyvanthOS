# The language

The kernel is written in a subset of PHP. This page says what is in the subset, what each part
turns into in C, and where it differs from the PHP you know. The compiler that does the turning is
described in [tools/polyc.md](tools/polyc.md).

## What "PHP" means here

The files are real PHP. An editor understands them, and `php -l` accepts them. But PHP never runs
them. There is no PHP interpreter in the kernel, because there is no operating system for one to
run on. Instead `polyc` reads the files and writes C, and clang compiles the C.

That has a few consequences:

- A PHP feature that needs the interpreter (exceptions, garbage collection, arrays that grow,
  strings you can join) does not exist here. The compiler refuses it with a message instead of
  guessing.
- The rules that differ from PHP are on this page. The main ones are that numbers have a fixed
  size, `/` divides whole numbers (so `7 / 2` is `3`), and a variable gets its type from the first value
  stored in it.
- Because the code is PHP-shaped, it reads like PHP: classes, static methods, constants, typed
  properties, `null`, `new`.

## Files and names

**One class per file.** `src/drivers/Serial.php` holds the class `Polyvanthos\Drivers\Serial`. The folder names
are the namespace in lower case, and the file name is the class name. That is the usual PHP
convention (PSR-4), except that the folders are lower case, which keeps `src/arch/x86_64/` looking
like it did before. `use` lines work as in PHP.

**Names in C.** The part of the namespace that is the project root is dropped, the rest is joined
with `_`, and the method name is added at the end. `Polyvanthos\Drivers\Serial::putc` is
`Drivers_Serial_putc` in C. Static properties get an extra `s`, as in `Arch_X86_64_IDT_s_idt`, because
PHP lets a property and a method share a name and C does not. This is also what you see in a
debugger or in `addr2line` output.

## Types

| Type | In C | Notes |
|---|---|---|
| `u8` `u16` `u32` `u64` | `uint8_t` ... `uint64_t` | Unsigned whole numbers of that many bits |
| `i8` `i16` `i32` `i64` | `int8_t` ... `int64_t` | Signed whole numbers |
| `int` | `int64_t` | The same as `i64`. PHP's own integer is 64 bits |
| `bool` | `bool` | `true` and `false` |
| `string` | `const char *` | Text that never changes. See below |
| `void`, `never` | `void` | `never` is for a function that does not come back, and becomes `noreturn` |
| `SomeClass` | `SomeClass *` | An object is the address of a struct |
| `?SomeClass`, `?string` | the same, may be `NULL` | `null` is a null pointer |

`u8`, `u64` and the others are not PHP types, but PHP does not check type names when it reads a
file, so they are accepted by `php -l`. polyc gives them their meaning. They are always these
types, whatever namespace the file is in.

One limit comes from PHP, which takes `u64` for a class name: it refuses a starting value on a
property or parameter of such a type. `private static u64 $count = 0;` is a PHP error. Leave the
value out, since a static property without one starts at zero anyway. `int`, `bool`, `string`
and `?SomeClass` can have starting values.

There are no floats, no unions, no `mixed` (except for variadic parameters, see below) and no
plain `array`. Fixed-size arrays are described further down.

## Numbers

Everything is a whole number, and all the arithmetic is done in 64 bits:

- If either side is `u64`, the result is `u64`. Otherwise it is `i64`.
- Storing a number into something smaller (a `u8` variable, a `u32` parameter, a `u16` property)
  cuts it down. `Port::outb($port, 0x1FF)` sends `0xFF`. This is the same as a C cast, and polyc
  writes the cast out, so the C compiler never has to guess.
- Comparisons turn both sides into the same type first, so comparing a `u64` with a negative
  number gives the answer you would get from the unsigned maths. Nothing is silently wrong
  because of C's conversion rules, which is the main reason polyc works this way.
- A literal is written again for each type it is used as. `0x8000000000000000` is a valid `u64`.
  Decimal literals must fit in a signed 64-bit number; write bigger ones in hex.

Operators: `+ - * / % & | ^ ~ << >>`, the comparisons `== != === !== < <= > >=` (`===` and `==`
mean the same here), `&& || !`, `?:` with three parts, and the assignment forms of the operators
above.

**`/` divides whole numbers.** In PHP, `7 / 2` is `3.5`, a float. The kernel has no floats, so here
`7 / 2` is `3`: the result is rounded toward zero, the same as C, and `-7 / 2` is `-3`. `/=` works
too. This is a deliberate difference from PHP, and it is the main reason the sources would behave
differently if you ran them under a real PHP interpreter. Dividing by a constant zero is a compile
error. Dividing by a variable that is zero at run time (or `i64` minimum by `-1`) is not checked: the
CPU raises a divide error (#DE), which the kernel reports as an exception. `/` and `%` also work in
constant expressions such as array sizes and case labels. `intdiv($a, $b)` still works and means
the same thing as `$a / $b`.

**Explicit conversions** are written like function calls named after the type: `u8($x)`,
`i32($x)`, `u64($x)` and the rest, and `bool($x)` (true if not zero). They are built into the
compiler, and PHP does not mind the names when it reads the file. PHP's own `(int)` casts are
refused, because they would not say how many bits.

**Variables have no declaration.** A variable gets the type of the first value stored in it:

```php
$a = 3;                 // int, which is i64
$b = u64(0);           // u64
$c = ISR::stub(3);      // u64, because that is what stub returns
```

After that, storing a different whole number type cuts it down to the variable's type. All
variables are declared at the top of the C function, so PHP's one-scope-per-function rule holds.
A variable that is read before anything is stored in it is an error. A name can't change type.

## Strings

A `string` is a pointer to bytes that end in a zero, and it never changes. Literals are what you
get: `'text'` and `"text\n"` (with the usual PHP escapes in double quotes). Strings can be joined
with `.`, but only at build time (see below). A string with variables in it, like `"free: $n pages"`,
works only for functions marked `#[Format]` (see below). There are no heredocs.

- `$s[$i]` is the byte at that position, as a `u8`. A string is compared by looking at its bytes,
  as `Serial::puts` does. Two strings can't be compared with `===`.
- **A one-character string is also a character.** Where a whole number is wanted, `'a'` is the
  number 97, worked out at build time. So you write `$c === '%'`, `case 'x':`, `$digit - '0'` and
  `Serial::putc("\n")`, with no `ord()`. This works for a literal, or a class constant whose value
  is a literal, such as `const NEWLINE = "\n"`. Where a string is wanted, it is still a string, so
  `Serial::puts('a')` prints the letter.
  Two things to watch for. A *variable* takes the type of the first value stored in it, so
  `$c = 'a'` makes `$c` a string, not a number; write `$c = u8('a')` if you want the number.
  And a variadic argument has no type to tell the compiler which you mean, so
  `Kprintf::printf('%c', 'a')` is an error: write `u8('a')` to pass the character or
  `Mem::addr('a')` to pass the text.
- **`.` joins constants at build time.** Each side must be a string literal, a whole-number constant
  (written in decimal), a class constant that holds either, or another join. So
  `'page size ' . self::PAGE_SIZE . ' bytes'` becomes one string in the C code and costs nothing at
  run time. A variable can't be joined: its value isn't known until the program runs, and there is
  nowhere to build a new string. `.=` is refused for the same reason. As in PHP 8, `+` and `-` are
  worked out before `.`, so `'n=' . 1 + 2` is `'n=3'`.
- **Variables in a string work for `#[Format]` functions.** `Kprintf::printf` is one: its first
  parameter is a printf format, and the values follow as variadic arguments. Given
  `Kprintf::printf("Usable memory: $mebibytes MiB ($pages pages)\n")`, the compiler writes the format
  `"Usable memory: %lu MiB (%lu pages)\n"` and passes the two values, so nothing is built at run time.
  You can write `$name`, `$object->property` and `{$expression}` (such as `{$list[0]}`); `$list[0]`
  without braces is refused. The conversion comes from the type: whole numbers in decimal, a `bool`
  as `true` or `false`, a string as text (`(null)` for null), an object as its address. A number is
  never printed as a character or in hex: for that write a printf format yourself (`%c`, `%lx`).
  A `%` in the text is printed as a `%`. Such a string can't be followed by more printf arguments,
  can't be joined with `.`, and is an error anywhere except as the first argument of a `#[Format]`
  function.
- The address of a string, if you need it, is `Mem::addr('text')`. That is how `Kprintf` handles
  `%s` and `(null)`.

## Classes

A class is a group of names. It can have:

- **Constants**, `const NAME = value;`. They become `#define`s, so they cost nothing at run time
  and can be used where C needs a constant. A constant can be a number, a bool, a string or a
  list.
- **Static properties and methods**, the bulk of the kernel. A static method is a C function. A
  static property is a global variable, and it can only start as a plain constant (see
  "No startup code" in the [overview](overview.md)).
- **Instance properties and methods**, which make the class a **struct**. See below.

Visibility works as in PHP and is checked: `private` and `protected` members can only be used
inside the class. Private methods become `static` C functions, so they don't clutter the symbol
table.

A class can't extend another, implement an interface, or use a trait. There is no virtual
dispatch. If you need it, it can be added to polyc later.

### Structs

A class with instance properties is a C struct, and an object is the address of one:

```php
#[Packed, Size(16)]
final class IDTEntry
{
    public u16 $offsetLow;
    public u16 $selector;
    public u8 $ist;
    public u8 $typeAttr;
    public u16 $offsetMid;
    public u32 $offsetHigh;
    public u32 $reserved;
}
```

- The fields are laid out in order, as in C. `#[Size(32)]` makes the compiler check the total, and
  `#[Packed]` removes the padding between fields, for things like the TSS that the CPU reads by
  byte offset.
- A property that holds another object has to be nullable (`?SomeClass`). It is a pointer, so it
  can be `null`.
- Reading and writing a field is `$gate->ist`, as usual.
- **`IDTEntry::at($address)`** turns an address into an `IDTEntry`. That is how the kernel looks at
  memory that something else handed over, such as an answer from Limine. It is available on
  every struct class.
- **`IDTEntry::SIZE`** is the size of the struct in bytes.
- `Mem::addr($gate)` is the address of an object.
- Methods on a struct use `$this`, and `$obj->method()` is a C function that takes the object as
  its first argument.

### `new`

`new Counter()` allocates room, sets the properties to their starting values and calls
`__construct`. There is nothing to allocate from by default, so polyc is told which static
method to call, with the `--allocator Class::method` option. That method takes a size in bytes and
returns an address, like a heap's `kmalloc`. Without the option, `new` is an error. The kernel does
not use `new` yet, so the Makefile doesn't pass the option, but the compiler tests do. The result
may be `null` if the allocator is out of memory. There is no `delete`: give the memory back with
whatever frees what the allocator gave out.

## Arrays

PHP arrays grow and can be keyed by anything. There is no such thing without an allocator. What
the kernel needs is a fixed number of elements of one type, so that is what exists.

```php
#[FixedArray('u8', 16384), Align(16)]
private static array $stack;               // uint8_t stack[16384]

#[FixedArray(IDTEntry::class, 256), Align(16)]
private static array $idt;                 // an array of 256 structs
```

- The type and the length are in the `#[FixedArray(...)]` attribute. The length is a constant.
- `$array[$i]` reads and writes an element. For an array of structs it gives the object (the
  address of that element), and you set its fields: `self::$idt[$v]->ist = 1;`.
- `count($array)` is the length.
- Arrays are zero at the start, or can be given a list of constants.
- **Constant lists** work too, for tables of text or numbers:

  ```php
  #[Count(32)]
  private const EXCEPTION_NAMES = ['Divide error', 'Debug', /* ... */];
  ```

  `#[Count(32)]` makes the compiler check there are exactly 32 entries.
- A struct class can have array properties as well, as the TSS does for its stack pointers.
- You can't assign a whole array or pass one around. Pass its address with `Mem::addr()`.

Scratch space in a function, such as the digits buffer in `Kprintf::putUint`, comes from
`Mem::stack($bytes)`. See [mem.md](mem.md).

## Statements

`if`, `elseif`, `else`, `while`, `do ... while`, `for` (with commas in the first and last part),
`foreach`, `switch`, `break`, `continue`, `return`, blocks, and expressions.

- `switch` is on whole numbers, and each `case` is a constant (a number, a character such as
  `'x'`, or a class constant). It falls through like C and PHP.
- `continue` has to be directly inside a loop. In PHP, `continue` inside a `switch` quietly acts
  like `break`, so polyc doesn't allow it and nobody is surprised.
- `break` and `continue` take no level.

### `foreach`

`foreach` goes through the elements of an array, the bytes of a string, or a `range`:

```php
foreach (self::$numbers as $n) { $total += $n; }          // each element
foreach (self::NAMES as $i => $name) { /* ... */ }         // with the index as the key
foreach ($text as $c) { Serial::putc($c); }                // each byte of a string, as a u8
foreach (range(0, 31) as $vector) { /* 0, 1, ... 31 */ }   // both ends are included
```

- It is a `for` loop over a hidden counter, so `break`, `continue` and the number rules work as in
  any other loop. The loop variable lasts after the loop, as in PHP, and takes the type of the
  first value stored in it. The key is the position (an `int`), starting at 0.
- **The list has to be a variable, a property, a constant or a string literal.** It is read again
  on every round, so something like `foreach (self::make() as $x)` is an error. Arrays are the
  fixed-size arrays described above, and a variadic parameter such as `$args` counts as one.
- **A string is walked up to its zero byte.** Real PHP can't loop over a string, so this is an
  addition. It replaces `for ($i = 0; $s[$i] !== 0; $i++)`.
- **An array of structs gives you a pointer to each element**, so `$counter->step = 5` changes the
  element itself. For an array of numbers you get a copy of the number, as in PHP.
- **By reference (`as &$x`) is not supported.**
- **`range($first, $last)` works as in PHP:** both ends are included, and it counts down when
  `$first` is larger than `$last`. That makes `range(0, $n - 1)` a trap when `$n` is 0, because it
  then runs for 0 and -1 (and with a `u64` `$n` the `- 1` wraps around to a huge number). Use it
  with constants, or use `for` when counting up to a variable. Unsigned numbers count down safely
  and stop at 0. `range()` is only allowed as the list of a `foreach`, and takes no step.

Not available: `try`/`catch`/`throw`, `goto`, `echo`, `unset`, `global`, closures,
`static` variables, `list`, `match`, `??`, `<=>`, `**`, references and the `.` operator.

## Calling functions

Only methods can be called, as `Class::method()` or `$object->method()`, plus a few built-in
functions: `intdiv`, `count`, the conversions `u8()` to `i64()` and `bool()`, and `range()`
(which only works inside `foreach`).

- Arguments are stored into the parameter types, so a number is cut down to fit.
- Parameters can have default values that are constants. Only `int`, `bool` and `string` parameters
  can have one in the source, because PHP itself rejects `u8 $x = 1` (it takes `u8` for a class
  name). Use `int $x = 1`: the value is cut down to the field or parameter it ends up in.
- A **variadic parameter**, `mixed ...$args`, collects the extra arguments. Each one is passed as one
  64-bit word (numbers widened, strings and objects as addresses, `null` as 0). Inside, `$args` is an
  array of `u64` and `count($args)` is how many. See [lib/kprintf.md](lib/kprintf.md).
- Named arguments and argument unpacking are not supported.

## Talking to the machine

PHP cannot say "run this CPU instruction". Three attributes cover everything the kernel needs:

- `#[Native]` on a method, or on a whole class (`#[Native('mem.h')]`). The body is empty; the
  real function is written in C in a header under `native/`, with the name the compiler would give
  it (`Arch_X86_64_Port_outb`). The compiler type-checks calls to it from the PHP signature.
- `#[Header('x86_64.h')]` on a class whose `#[Native]` methods live in that header.
- `#[Intrinsic]` for the few things the compiler writes itself: `Mem::addr`, `Mem::stack`.
  (The conversions `u8()`, `u64()` and so on are built into the compiler and need no attribute.)

`#[Export('exception_handler')]` goes the other way: it gives a PHP method a plain C name, so
assembly (or Limine, in the case of `kmain`) can call it.

## Attribute summary

| Attribute | On | Meaning |
|---|---|---|
| `Native('h')` / `Native` | class / method | Implemented in C. `'h'` is the header to include |
| `Header('h')` | class | Header with the `#[Native]` methods |
| `Intrinsic` | method | The compiler writes it |
| `Export('name')` | method | Use this exact C name, with external linkage |
| `FixedArray(type, length)` | property | Fixed-size array. `type` is `'u8'` and the like, or `Class::class` |
| `Count(n)` | constant | Check that a list has `n` entries |
| `Size(n)` | class | Check that the struct is `n` bytes |
| `Packed` | class | No padding between fields |
| `Align(n)` | class / static property | Align to `n` bytes |
| `Section('name')` | static property | Put it in this section of the file |
| `Used` | static property | Keep it even if nothing reads it |
| `Volatile` | static property | Something else (Limine, the hardware) may change it |
| `Format` | method | The first parameter is a printf format and the last is variadic, so a string with variables in it can be passed as the format |

## Mistakes the compiler catches

Every message has the file and line, like `src/drivers/Serial.php:58: error: ...`. Some it will give you:

- using a variable before it has a value;
- passing a string where a number is expected, or a number where an object is expected;
- a float, a PHP cast, string interpolation and the other unsupported things above;
- using a private member from another class, calling a method that doesn't exist, passing too many
  arguments;
- a `switch` case that isn't constant, `continue` inside a `switch`;
- a struct that is not the size `#[Size]` says, or a list that is not the length `#[Count]` says;
- `return` in a function that returns `never`, or no value in a function that returns one.

The tests in `tools/polyc/tests/errors/` check many of these.
