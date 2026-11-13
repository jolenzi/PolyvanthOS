<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Drivers;

use Polyvanthos\Arch\X86_64\Port;
use Polyvanthos\Cast;

final class Serial
{
    public const DEFAULT_BAUD = 115200;

    private const COM1_BASE = 0x3F8;

    // Register offsets from the base port.
    private const UART_DATA = 0; // TX/RX byte; divisor low byte when DLAB=1
    private const UART_IER = 1; // Interrupt enable; divisor high byte when DLAB=1
    private const UART_FCR = 2; // FIFO control (write-only)
    private const UART_LCR = 3; // Line control
    private const UART_MCR = 4; // Modem control
    private const UART_LSR = 5; // Line status

    // Line control register bits.
    private const LCR_8N1 = 0x03; // 8 data bits, no parity, 1 stop bit
    private const LCR_DLAB = 0x80; // Divisor latch access: remaps +0/+1 to the divisor

    // FIFO control: enable, clear both FIFOs, 14-byte receive trigger.
    private const FCR_ENABLE_CLEAR_14 = 0xC7;

    // Modem control: DTR + RTS + OUT2.
    private const MCR_READY = 0x0B;

    // Line status register bits.
    private const LSR_TX_EMPTY = 0x20; // Transmit holding register can accept a byte

    private const UART_BASE_BAUD = 115200; // UART clock
    private const TX_TIMEOUT = 100000; // Timeout for putc busy-wait

    public static function init(u32 $baudRate): void
    {
        if ($baudRate === 0 || $baudRate > self::UART_BASE_BAUD) {
            $baudRate = self::DEFAULT_BAUD; // Can't panic here: panic prints through this driver
        }
        $divisor = Cast::u16(self::UART_BASE_BAUD / $baudRate);

        Port::outb(self::COM1_BASE + self::UART_IER, 0x00); // polled mode: no UART interrupts

        Port::outb(self::COM1_BASE + self::UART_LCR, self::LCR_DLAB); // expose the divisor registers
        Port::outb(self::COM1_BASE + self::UART_DATA, $divisor & 0xFF);
        Port::outb(self::COM1_BASE + self::UART_IER, $divisor >> 8); // with DLAB=1, +1 is the divisor high byte

        Port::outb(self::COM1_BASE + self::UART_LCR, self::LCR_8N1); // sets the format and clears DLAB
        Port::outb(self::COM1_BASE + self::UART_FCR, self::FCR_ENABLE_CLEAR_14);
        Port::outb(self::COM1_BASE + self::UART_MCR, self::MCR_READY);
    }

    public static function putc(u8 $c): void
    {
        $timeout = self::TX_TIMEOUT;
        while ((Port::inb(self::COM1_BASE + self::UART_LSR) & self::LSR_TX_EMPTY) === 0 && --$timeout > 0) {
            // busy-wait until the transmitter can take another byte, or timeout
        }
        if ($timeout > 0) {
            Port::outb(self::COM1_BASE + self::UART_DATA, $c);
        }
        // On timeout the character is dropped.
    }

    public static function puts(string $s): void
    {
        for ($i = 0; $s[$i] !== 0; $i++) {
            if ($s[$i] === "\n") {
                self::putc("\r");
            }
            self::putc($s[$i]);
        }
    }
}
