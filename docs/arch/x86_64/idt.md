# Interrupts and exceptions

Files: `src/arch/x86_64/IDT.php`, `src/arch/x86_64/IDTEntry.php`,
`src/arch/x86_64/InterruptFrame.php`, `src/arch/x86_64/ISR.php`, `src/arch/x86_64/asm/ISR.S`.

An exception is the CPU telling the kernel that the code it was running did something it can't
carry out: divide by zero, run an invalid instruction, touch memory that isn't mapped. Without
any setup, the CPU can't find a handler, tries a double fault, fails again, and resets the
machine (a triple fault). The result is a QEMU window that reboots forever and says nothing.
This code makes crashes tell us what happened.

Today only the 32 CPU exceptions are handled. Hardware interrupts (timer, keyboard) will use the
same machinery later.

## How the CPU finds a handler

The CPU has a table with 256 slots, the IDT (Interrupt Descriptor Table). Each slot is called a
vector. When something happens, the CPU takes the vector number and looks at that slot, which
holds the address of the code to run.

Vectors 0 to 31 are reserved for CPU exceptions. For example 0 is divide error, 6 is invalid
opcode, 13 is general protection fault and 14 is page fault. The rest are free for hardware
interrupts and software interrupts.

### One IDT entry

Each slot is 16 bytes, called a gate:

```
offset_low   16 bits   handler address, bits 0-15
selector     16 bits   which code segment to run in
ist           8 bits   special stack number (0 = none)
type_attr     8 bits   gate type and flags
offset_mid   16 bits   handler address, bits 16-31
offset_high  32 bits   handler address, bits 32-63
reserved     32 bits   must be 0
```

The 64-bit address is split in three pieces for historical reasons: the format started in 32-bit
x86 and was extended without changing the first 8 bytes. `setGate` cuts the address into those
pieces with shifts and masks.

`selector` is `0x08`, our kernel code segment from the GDT. That is the reason the GDT has to be
loaded first.

`type_attr` is `0x8E`, in binary `1000 1110`: present, privilege level 0, and the type 0xE, which
is a 64-bit interrupt gate. An interrupt gate also clears the interrupt flag on entry, so the
handler can't be interrupted in the middle of its work.

`ist` is 0, meaning the CPU keeps using the current stack. That has a downside, covered at the
end.

Unused slots stay all zeros. The "present" bit is 0, so the CPU treats them as not there. If one
of them fires anyway, the CPU escalates to a double fault, then a triple fault.

## Why we need assembly stubs

The CPU jumps to the handler address and tells it nothing else. It doesn't say which vector
fired. If every slot pointed at the same function, the function would have no idea what
happened.

So there is one tiny assembly stub per vector, 32 of them. Each one pushes its own vector number
and jumps to shared code. `ISR.S` builds them with a macro so we write the pattern once.

### Error codes

Some exceptions push an extra number, the error code, before the handler starts. Page fault and
general protection fault do, for example. Others don't. If the stubs left it like that, the
stack would look different depending on the exception, and the shared code would have to know
which was which.

To make them all look the same, the stubs for exceptions with no error code push a fake `0`
first. There are two macros: `ISR_NOERR` pushes the fake zero and then the vector number, and
`ISR_ERR` pushes only the vector number, since the CPU already pushed a real code.

These vectors have a real error code: **8, 10, 11, 12, 13, 14, 17, 21, 29 and 30**. This list
comes from the Intel manual. Getting one wrong shifts the whole stack by 8 bytes and gives
nonsense in the crash report.

## The stack at the moment of the call

Here is the stack when `isr_common` is about to call the PHP handler, drawn with high addresses at the top.
Pieces are pushed from the top down.

```
ss              }
rsp             }
rflags          }  pushed by the CPU
cs              }
rip             }
error code         pushed by the CPU, or a fake 0 from the stub
vector             pushed by the stub
rax                }
rbx                }
 ...               }  pushed by isr_common, one at a time
r14                }
r15                }  <- rsp points here
```

The CPU always pushes `ss` and `rsp` in 64-bit mode, even when there is no change of privilege.
`rip` is the address of the instruction that was running. That is the most useful value in the
whole report.

`isr_common` pushes the 15 general registers (`rax` first, `r15` last), then passes `rsp` as the
first argument. The stack grows downward, so the most recently pushed register, `r15`, is at the
lowest address.

### InterruptFrame

On the PHP side, the `InterruptFrame` class lays out the same 22 values as a struct:

```php
#[Size(176)]
final class InterruptFrame
{
    public u64 $r15, $r14, $r13, $r12, $r11, $r10, $r9, $r8;
    public u64 $rbp, $rdi, $rsi, $rdx, $rcx, $rbx, $rax;
    public u64 $vector, $errorCode;
    public u64 $rip, $cs, $rflags, $rsp, $ss;
}
```

The fields are in memory order, lowest address first, so they come out reversed from the push
order. The handler receives this object, which is really a pointer to the stack. `$frame->rip` reads
the right slot.

`#[Size(176)]` makes the compiler check that the struct is 22 * 8 bytes. It can't check the
assembly, so if you add or remove a push in `ISR.S`, change the class too. A mismatch won't give a build
error. The names will just point at the wrong values.

### Stack alignment

The calling convention says the stack pointer must be a multiple of 16 right before a `call`.
The CPU aligns the stack when it starts an exception, then we push 7 values from the CPU and the
stub plus 15 registers, which is 22 values of 8 bytes, or 176 bytes. 176 is a multiple of 16, so
it lines up without any extra adjustment. If you change the number of pushes, check this.

`cld` clears the direction flag. The calling convention says it must be clear when a function
starts, and the code that was interrupted might have left it set.

## The handler

`IDT::handleException` prints the exception name, the error code, and all the registers. The
assembly calls it by the plain C name `exception_handler`, which the `#[Export('exception_handler')]`
attribute on the method gives it:

```
CPU exception 14: Page fault (error code 0x0)
RIP 0xffffffff8000002e  CS 0x8  RFLAGS 0x10082  RSP 0xffff800007f94ff0  SS 0x10
RAX ...
CR2 0x1000
PANIC: CPU exception
```

For a page fault (vector 14) it also reads `cr2`. The CPU puts the address that was being
accessed in that register. It's only set for page faults, and for any other exception it holds a
stale value, so it's only printed for vector 14.

After the report, it calls `Panic::now`. There is no way back yet. Returning from an exception would
need a different assembly path that restores the registers and runs `iretq`, and nothing in the
kernel is ready to fix the problem and continue.

### Reading a crash report

1. Find the vector and name on the first line. That tells you what kind of error it was.
2. Take `RIP` and run `addr2line -e kernel <RIP>`. That gives the source line.
3. For a page fault, `CR2` is the address that was touched. An address near 0 is usually a null
   address.
4. The page fault error code is a set of bits: bit 0 set means the page was present and
   something else was wrong (a permissions problem), clear means the page wasn't mapped; bit 1
   means it was a write; bit 2 means it came from user mode; bit 4 means it was an instruction
   fetch. An error code of 0 is "page not mapped, read, from the kernel".
5. For a general protection fault, the error code is a segment selector, or 0 if it wasn't about
   a segment.

## Setting it up

`IDT::init` fills slots 0 to 31 from `isr_stub_table`, a table of the 32 stub addresses defined at
the bottom of `ISR.S`, and then runs `lidt` with a `TablePointer`, the same shape as the one
used for the GDT. `ISR::stub($i)` is a native function that reads the table.

The IDT array is `#[Align(16)]`. The CPU doesn't require it, but Intel recommends it for speed.

## Known weaknesses

- **No separate stack for faults.** With `ist` at 0, the CPU pushes the frame on the current
  stack. If the exception was caused by running out of stack, there's no room to push, the
  attempt fails, and the machine triple faults without a message. The usual fix is a TSS with an
  IST entry, giving the double fault handler its own stack.
- **Exceptions are fatal.** See above.
- **Nothing for hardware interrupts.** Slots 32 to 255 are empty.
