# Memory access and number conversion

Files: `src/Mem.php`, `src/Cast.php`, `native/mem.h`.

Two small classes that most of the other code leans on.

## Mem: reading and writing at an address

A kernel has to touch memory that no variable owns: a page the allocator just handed out, the
table Limine built, a device. In C that means a pointer. PHP has no pointers, so the kernel keeps
**addresses as plain integers** (`u64`) and uses `Mem` to look at what is there:

| Method | What it does |
|---|---|
| `Mem::read8($address)` / `read16` / `read32` / `read64` | Read a `u8` / `u16` / `u32` / `u64` from the address |
| `Mem::write8($address, $value)` / `write16` / `write32` / `write64` | Write one |
| `Mem::addr($x)` | The address of an object, a static array or a string |
| `Mem::stack($bytes)` | The address of `$bytes` bytes of scratch space on the stack |

```php
$scratch = Mem::stack(8);                       // an address, as a u64
Mem::write64($scratch, 0x1122334455667788);
$back = Mem::read64($scratch);
```

The first four are in `native/mem.h`. Each is a `static inline` C function that does one access
through a `volatile` pointer, so the compiler can't skip, merge or reorder them. That is slightly
more than ordinary RAM needs and exactly what device memory needs, and it means the same function
works for both. The names are `Mem_read8`, `Mem_write64` and so on.

`Mem::addr` and `Mem::stack` are written by the compiler itself:

- `Mem::addr` accepts an object, a static array or a string, which is why its signature in
  `Mem.php` says `u64` only for the editor's sake. `Mem::addr(self::$gdt)` is the address of the
  table. `Mem::addr($gate)` is the address of an `IDTEntry`.
- `Mem::stack(64)` reserves 64 bytes in the C function's stack frame and returns their address.
  The size has to be a constant, and the space is gone when the function returns. `Kprintf::putUint`
  uses it for its digit buffer.

### Looking at a struct

Raw `read` and `write` calls are fine for a byte or a word. For something with fields, give the
address a type with `at`:

```php
$gate = IDTEntry::at($address);         // $address is a u64
$gate->ist = 1;                         // writes the byte at $address + 4
```

`at` exists on every class that has instance properties. See [the language](language.md#structs).
There is no check that the address really holds that struct. Same as a cast in C.

### Why not poke and peek

`Mem::read64` and friends say what size they touch, which is the part that is easy to get wrong
with a pointer. They also keep the unsafe part in one place: if you search the PHP for `Mem::` you
find every spot that touches raw memory.

## Cast: choosing a size

PHP has one integer type, and C has many. polyc keeps track of the size of every number (see
[the language](language.md#numbers)), and `Cast` is how you change it on purpose.

| Method | Result |
|---|---|
| `Cast::u8($x)`, `u16`, `u32`, `u64` | The low 8, 16, 32 or 64 bits, as an unsigned number |
| `Cast::i8($x)`, `i16`, `i32`, `i64` | The low bits, as a signed number |
| `Cast::bool($x)` | `true` if `$x` is not zero |

Two typical uses:

```php
$value = Cast::i32($args[$next]);    // Kprintf: a %d argument is 32 bits, sign-extended
$total = Cast::u64(0);               // a variable that starts out as a u64
```

The second one matters because a variable takes its type from the first value stored in it, and a
plain `0` is an `int`. `Cast::u64(0)` is a `u64` that starts at zero.

A `Cast` on a constant is worked out at build time. On anything else, the compiler writes the C
cast. It never changes the value beyond cutting off the bits that don't fit.
