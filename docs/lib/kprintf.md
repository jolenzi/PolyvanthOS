# kprintf

Files: `src/lib/Kprintf.php`, `src/SelfTest.php`.

`kprintf` is the kernel's `printf`. It is the method `Kprintf::printf`. It formats text and
sends it out over the serial port. This
page covers the number printing code first, because it's the part with algorithms in it, and then
the format string parser.

## Printing numbers: putUint

`Kprintf::putUint($value, $base)` prints an unsigned number in any base from 2 to 16.

The obvious way to get the digits is to divide by the base and look at the remainder. For 1234 in
base 10:

```
1234 % 10 = 4     1234 / 10 = 123
 123 % 10 = 3      123 / 10 = 12
  12 % 10 = 2       12 / 10 = 1
   1 % 10 = 1        1 / 10 = 0   stop
```

The digits come out as 4, 3, 2, 1. That's backwards. We want to print 1 first. So the function
stores the digits in a small buffer as they come out, then prints the buffer from the end to the
start. The buffer is 64 bytes of scratch space on the stack, from `Mem::stack(64)`, and the
digits go in and out with `Mem::write8` and `Mem::read8`.

Some decisions worth knowing about:

**The buffer holds 64 characters.** The worst case is the largest 64-bit number printed in base 2,
which is 64 digits. A buffer of 64 can never overflow, whatever the input. We use a fixed buffer
because there is no heap.

**It's a `do { } while` loop.** If the value is 0, a normal `while (value != 0)` loop would run
zero times and print nothing. Zero has to print as `0`. A `do` loop always runs once, so the
first digit is always produced and no special case is needed.

**Digits come from a lookup string.** `"0123456789abcdef"` indexed by the remainder. It's shorter
and faster than adding `'0'` or `'a'` with an `if`, and it handles every base up to 16 the same
way.

**The printing loop counts down with `$i > 0` and reads the byte at `$buffer + $i - 1`.** If the
counter were unsigned, a loop written as `$i >= 0` would never stop, because an unsigned number is
always at least 0 and wraps around instead of going negative. Reading one position behind the
counter works for both kinds of number.

**A bad base calls `Panic::now()`.** Base 0 would divide by zero, and base 1 would never finish. Base
above 16 would index past the end of the digit string. No caller can mean any of those, so it's a
bug in the kernel, and the loud failure is the right one.

## Printing signed numbers: putInt

```
if value is negative, print '-'
print the magnitude with putUint in base 10
```

The tricky part is getting the magnitude. For a negative number you'd normally write `-value`. But
the most negative 64-bit number, `-9223372036854775808`, has no positive partner, because the
largest positive value is `9223372036854775807`. Negating it overflows, and overflow on signed
numbers is undefined behaviour in C, so the compiler is allowed to do anything.

The fix is to do the negation as unsigned numbers, where wrapping around is defined:

```php
$magnitude = Cast::u64($value);
if ($value < 0) {
    $magnitude = 0 - $magnitude;
}
```

Converting a negative number to unsigned gives 2^64 minus its size. Subtracting that from 0
wraps around and lands on the real size. For -5, the cast gives 2^64 - 5, and `0 - (2^64 - 5)`
is 5. For the most negative number, the cast gives 2^63, and `0 - 2^63` wraps to 2^63, which as an
unsigned number is exactly `9223372036854775808`. It works for every value without a special
case.

All the number code uses 64 bits. A 32-bit value is widened first. That way there's one routine
for every size.

## The format string

`Kprintf::printf($fmt, ...$args)` walks the format string one character at a time.

- A normal character is printed as it is.
- A `%` starts a conversion. The code looks at what follows.

After the `%` it counts any `l` characters (so `%d` has none, `%ld` has one, `%lld` has two), and
then reads the conversion letter:

| Letter | What it prints |
|---|---|
| `c` | one character |
| `s` | a string, or `(null)` if the argument is `null` |
| `d` | a signed decimal number |
| `u` | an unsigned decimal number |
| `x` | an unsigned number in lowercase hex |
| `p` | an address: `0x` followed by hex |
| `%` | a literal percent sign |

Anything else prints as `%` followed by the letter, so a typo in a format string shows up in the
output and doesn't vanish. If the string ends right after a `%` or `%l`, the loop stops instead of
reading past the end of the string.

### Variable arguments

`printf` is declared as `printf(string $fmt, mixed ...$args)`. In PHP, `...$args` collects any
number of extra arguments into an array, and that is how the method sees them.

PHP values have a type, but a C vararg list does not, and the kernel has no type information at
run time. So polyc passes every extra argument as one 64-bit word: whole numbers are widened (a
negative number is sign-extended), a string is passed as its address, `null` as 0. A call like
`printf("%d %s", -1, "x")` becomes a count plus an array of two words.

The method then decides what each word means from the format string. That is why the `l` count
matters:

```
%d   -> the low 32 bits as a signed number     %u / %x   -> the low 32 bits, unsigned
%ld  -> all 64 bits, signed                    %lu / %lx -> all 64 bits, unsigned
%lld -> the same as %ld                        %llu      -> the same as %lu
```

In the code, `%d` without an `l` is `Cast::i32($args[$next])`, which cuts the word to 32 bits and
reads it as signed. With an `l` it is `Cast::i64`. Going through a word like this means a number
and its size can never get out of step the way they can with C's `va_arg`.

A `%s` argument is the address of the text, so `printf` reads it a byte at a time with
`Mem::read8` until it finds a zero. An address of 0 prints `(null)`. The `%c` argument is the
character code, for example `Cast::u8('a')`.

### What is checked

The compiler checks that every argument can be passed (a whole number, a bool, a string, an
object or `null`), but it does not match the arguments against the format. A `%d` with no
argument reads past the end of the array and prints whatever is in memory there, and a
`%s` with a number that is not an address crashes. Count the arguments when you add a call.

## Output

Every character goes out through one function, `emit()`, which turns `\n` into `\r\n` and calls
`Serial::putc`. Keeping it in one place means that if we add a screen or a log buffer later, only
`emit()` needs to change.

## The selftest

`SelfTest::kprintf` runs at boot and prints about twenty cases with the expected result in a comment
next to each: zero, the largest values, negative numbers, the smallest 64-bit number and a null string. When you
change `kprintf`, run `make run` and compare the output by eye, or let `make test` check that it
finishes without a panic.

## Limits

- No field width or zero padding, so no `%08x`.
- No `%X`, `%o`, `%z` or `%h`.
- An unknown conversion loses any `l` in front of it: `%lq` prints `%q`.
- Not safe to call from two places at once. There is only one CPU for now.
