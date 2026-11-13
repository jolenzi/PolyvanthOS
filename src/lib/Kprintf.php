<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Lib;

use Polyvanthos\Drivers\Serial;
use Polyvanthos\Mem;

final class Kprintf
{
    private const DIGITS = '0123456789abcdef';

    // All kprintf output goes through here, so a framebuffer or log buffer can be added later.
    private static function emit(u8 $c): void
    {
        if ($c === "\n") {
            Serial::putc("\r"); // serial terminals need CR+LF
        }
        Serial::putc($c);
    }

    /** Print $value in $base (2..16), lowercase digits, no prefix, no padding. */
    public static function putUint(u64 $value, u32 $base): void
    {
        if ($base < 2 || $base > 16) {
            Panic::now('Invalid base for put_uint');
        }

        $buffer = Mem::stack(64);
        $counter = 0;

        // Extract digits
        do {
            $remainder = $value % $base;
            Mem::write8($buffer + $counter, self::DIGITS[$remainder]);
            $counter++;
            $value /= $base;
        } while ($value !== 0);

        // Print in reverse
        for ($i = $counter; $i > 0; --$i) {
            self::emit(Mem::read8($buffer + $i - 1));
        }
    }

    /** Print $value in base 10 with a leading '-' if negative. */
    public static function putInt(int $value): void
    {
        $magnitude = u64($value);
        if ($value < 0) {
            self::emit('-');
            // Negate in unsigned arithmetic: well-defined even for the smallest number.
            $magnitude = 0 - $magnitude;
        }
        self::putUint($magnitude, 10);
    }

    /**
     * Supported conversions: %c %s %d %u %x %p %%
     * %d, %u and %x also accept the l and ll length modifiers, for example %lu or %llx.
     * Every argument is passed as one 64-bit word. A %s argument is the address of the text.
     */
    #[Format]
    public static function printf(string $fmt, mixed ...$args): void
    {
        $next = 0; // index of the next argument

        for ($p = 0; $fmt[$p] !== 0; ++$p) {
            $c = $fmt[$p];

            if ($c !== '%') {
                // Ordinary character
                self::emit($c);
                continue;
            }

            // Handle conversion
            ++$p; // Move to the conversion letter
            if ($fmt[$p] === 0) {
                break; // End of string after '%', stop cleanly
            }

            // Count the 'l' characters
            $longCount = 0;
            while ($fmt[$p] === 'l') {
                ++$longCount;
                ++$p;
            }

            if ($fmt[$p] === 0) {
                break; // End of string after "%l", stop cleanly
            }

            $conversion = $fmt[$p];
            switch ($conversion) {
                case 'c':
                    self::emit(u8($args[$next++]));
                    break;
                case 's':
                    self::putString($args[$next++]);
                    break;
                case 'd':
                    // Without an l the argument is an int (32 bits), so sign-extend it.
                    self::putInt($longCount === 0 ? i32($args[$next]) : i64($args[$next]));
                    $next++;
                    break;
                case 'u':
                    self::putUint($longCount === 0 ? u32($args[$next]) : $args[$next], 10);
                    $next++;
                    break;
                case 'x':
                    self::putUint($longCount === 0 ? u32($args[$next]) : $args[$next], 16);
                    $next++;
                    break;
                case 'p':
                    self::emit('0');
                    self::emit('x');
                    self::putUint($args[$next++], 16);
                    break;
                case '%':
                    self::emit('%');
                    break;
                default:
                    self::emit('%');
                    self::emit($conversion);
                    break;
            }
        }
    }

    /** Print the text at $address, which ends with a zero byte. Address 0 prints (null). */
    private static function putString(u64 $address): void
    {
        if ($address === 0) {
            $address = Mem::addr('(null)');
        }
        while (Mem::read8($address) !== 0) {
            self::emit(Mem::read8($address));
            $address++;
        }
    }
}
