# GDT

Files: `src/arch/x86_64/GDT.php`, `src/arch/x86_64/TablePointer.php`.

The GDT (Global Descriptor Table) is a table in memory that describes segments. The CPU reads it
whenever a segment register is loaded.

## Why it exists at all

On old 16-bit and 32-bit x86, segments did real work. Each one had a start address and a size, and
every memory access was checked against them. 64-bit mode turned most of that off. The table is
still required, and a few fields still matter:

- whether an entry is code or data,
- the privilege level (ring 0 for the kernel, ring 3 for user programs),
- the L flag, which says "this code segment is 64-bit".

## Why we load our own

Limine already set up a GDT, and the kernel is running on it right now. We replace it anyway.

- It belongs to the bootloader. Nothing promises it stays where it is once we start reusing the
  memory Limine was using.
- The IDT we load next needs a code segment selector. It's better that this points into a table
  we own and know the layout of.
- Later we'll need entries Limine doesn't give us, like user mode segments and a TSS.

Limine's selectors were 0x28 for code and 0x30 for data. Ours are 0x08 and 0x10.

## The table

We have three entries, 8 bytes each:

| Index | Offset | Selector | What it is |
|---|---|---|---|
| 0 | 0x00 | 0x00 | Null. The CPU requires it to be all zeros. Loading it means "no segment". |
| 1 | 0x08 | 0x08 | Kernel code |
| 2 | 0x10 | 0x10 | Kernel data |

A selector is just the byte offset of the entry in the table, with the lowest 3 bits reserved for
the table type and the privilege level. Both are 0 for us, so selector = index * 8.

### What's in an entry

The 64 bits are split like this:

```
bits  0-15   limit (low)       ignored in 64-bit mode
bits 16-39   base  (low)       ignored in 64-bit mode
bits 40-47   access byte       matters
bits 48-51   limit (high)      ignored
bits 52-55   flags             matters
bits 56-63   base  (high)      ignored
```

Base and limit are left at 0. Only the access byte and the flags are set.

**Kernel code: `0x9A`, plus the L flag.** In binary the access byte is `1001 1010`:

```
1     present
00    privilege level 0 (kernel)
1     this is a code or data segment (not a system segment)
1     executable
0     can only run from its own privilege level
1     readable
0     the CPU sets this itself when the segment is used
```

The L flag is bit 53 of the entry, the second bit of the flags nibble. It says "64-bit code".

**Kernel data: `0x92`.** In binary, `1001 0010`. It is the same as above, but not executable and
writable.

Shifting these into place gives:

```
code  0x00209A0000000000
data  0x0000920000000000
```

The code builds them with `0x9A << 40`. In PHP every number is 64 bits wide, and polyc writes
the C so that the shift is done in 64 bits too. (In plain C, shifting a 32-bit `int` by 40 is
undefined, so that is a trap the compiler steps around for you.)

## Loading it

The `lgdt` instruction takes a pointer to a small structure:

```php
#[Packed, Size(10)]
final class TablePointer
{
    public u16 $limit; // size of the table minus 1
    public u64 $base; // address of the table
}
```

`#[Packed]` stops the compiler from putting padding between the two fields, and `#[Size(10)]`
makes it check the total. The CPU expects the
address right after the 2-byte limit, with nothing in between. The limit is "size minus 1" because
that is how the instruction defines it, so a 24-byte table has a limit of 23.

`TablePointer` is shared with the IDT, because `lidt` reads the same shape. `GDT::init` fills in
a static instance when it runs. It can't be done in the declaration, because polyc only accepts
plain constants as the starting value of a static property, and the table's address is not one.

## Reloading the segment registers

`lgdt` only tells the CPU where the new table is. It doesn't reload any segment register. Each
register has a hidden copy of the descriptor it last loaded, and the CPU keeps using that copy
until the register is loaded again. So after `lgdt` we are still running with Limine's
descriptors.

**The data registers** (`ds`, `es`, `fs`, `gs`, `ss`) can be set with `mov`, but only from a
general register, not from a constant. `CPU::loadDataSegments` is a short C function with inline asm. It lets the compiler pick the
register (`"r"`) and uses `%w0`, which means "the 16-bit name of operand 0". Segment registers
are 16 bits wide.

**`cs`, the code segment, can't be set with `mov` at all.** Changing it changes where the CPU
fetches instructions from, so it is only allowed through instructions that also change the
instruction pointer: far jumps, far calls and far returns. `CPU::loadCodeSegment` uses a far return:

```
push the new cs
push the address of the label right after this code
lretq
1:
```

`lretq` pops an address and a code segment off the stack and goes there. We pushed the address of
the very next instruction, so execution carries on exactly where it was, but now under the new
code segment. The `leaq 1f(%rip)` instruction gets the label's address; `1f` means "the
next label called 1, looking forward".

## Not in the table yet

- A TSS (task state segment). It's needed for switching stacks on an interrupt, and it's how a
  safe double fault handler works.
- User mode code and data segments (ring 3).
- `fs` and `gs` bases. Those are set through model-specific registers, not the GDT, and kernels
  use them later for per-CPU data.
