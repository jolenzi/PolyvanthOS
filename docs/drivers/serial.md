# Serial port driver

Files: `src/drivers/Serial.php`. It uses `src/arch/x86_64/Port.php`.

## Why serial first

Before we can print anything we need somewhere to print to. A screen driver needs a lot of code.
A serial port needs about twenty lines. QEMU can connect it to your terminal with
`-serial stdio`, real hardware can use a cable, and it keeps working even when much of the kernel
is broken, which is exactly when you need output.

## The hardware

A PC has a UART chip (a 16550 or something that acts like one) for each serial port. The first
one, COM1, lives at I/O port `0x3F8`. It has eight registers at ports `0x3F8` to `0x3FF`. In the
code they are called by their offset from the base:

| Offset | Name | What it does |
|---|---|---|
| +0 | DATA | Send or receive one byte |
| +1 | IER | Turn UART interrupts on or off |
| +2 | FCR | Control the FIFO buffers (write only) |
| +3 | LCR | Line control: data format |
| +4 | MCR | Modem control |
| +5 | LSR | Line status: is the chip ready? |

I/O ports are a separate address space from memory. You can't read them with a normal pointer.
The CPU has two special instructions, `in` and `out`, wrapped in the `Port` class. See
[CPU helpers](../arch/x86_64/cpu.md) for how those work.

## init(): setting up the port

The order of these steps matters.

1. **Write 0 to IER.** This turns off all UART interrupts. We send bytes by polling, and we
   don't have an IDT for hardware interrupts yet. A UART interrupt arriving at the wrong moment
   would be a bad surprise.
2. **Set the DLAB bit in LCR.** DLAB means "divisor latch access". While it is set, ports +0 and
   +1 stop being DATA and IER and become the two halves of the baud rate divisor. It is a way of
   fitting more registers into the same eight ports.
3. **Write the divisor.** Low byte to +0, high byte to +1.
4. **Write `0x03` to LCR.** That sets 8 data bits, no parity and 1 stop bit ("8N1"), and since
   bit 7 is now 0, it also clears DLAB. Ports +0 and +1 go back to normal.
5. **Write `0xC7` to FCR.** Bit 0 turns the FIFOs on, bits 1 and 2 clear them, and bits 6 and 7
   set the receive trigger level to 14 bytes. We only send, so the trigger level doesn't matter
   yet. It's the usual value.
6. **Write `0x0B` to MCR.** That is DTR, RTS and OUT2. DTR and RTS tell the other end we're
   ready, and some adapters won't talk without them. OUT2 is what connects the UART's interrupt
   line to the interrupt controller on a PC. We don't use interrupts yet, but we'll need it
   later, so it is set now.

### The baud divisor

The UART runs from a clock that, after its internal division by 16, gives 115200 ticks a second.
The baud rate is that divided by the divisor you write:

```
divisor = 115200 / baud
```

| Baud | Divisor |
|---|---|
| 115200 | 1 |
| 57600 | 2 |
| 9600 | 12 |

This is integer division, so a baud rate that doesn't divide evenly gets rounded down to the
nearest possible one. 50000 would give divisor 2, which is really 57600.

`init` rejects two bad inputs. A baud of 0 would divide by zero. A baud above 115200 would give
a divisor of 0, which is not valid. Both fall back to the default, 115200. We can't call
`Panic::now()` here because it prints through this same driver.

## putc(): sending one character

```
wait until LSR bit 5 says the transmitter is empty
write the byte to DATA
```

Bit 5 of the line status register means "the chip can take another byte". We loop reading LSR
until it is set.

The loop also has a timeout of 100000 tries. If there is no serial port at all (some real
machines don't have one), the bit might never be set, and without the timeout the kernel would
hang on its first print. With the timeout it gives up and drops the character. That's the right
trade: lost output is better than a dead kernel.

## puts(): sending a string

`puts` sends a character at a time and adds a carriage return before every newline. Serial
terminals treat the two separately: `\n` moves down a line and `\r` goes back to the start of
the line. Send only `\n` and your text staircases across the screen.

`Kprintf::printf` has its own copy of this rule in `emit()`, because it calls `putc` directly. The two
never stack up, since `Kprintf::printf` doesn't go through `puts`.

## Limits

- Send only. There is no way to read input yet.
- COM1 only, and not configurable.
- No hardware flow control.
- Characters can be dropped on timeout.
- Polling wastes CPU time. When we have interrupts and a scheduler, this should move to
  interrupt-driven output with a buffer.
