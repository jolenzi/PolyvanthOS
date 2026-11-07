<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Lib;

use Polyvanthos\Arch\X86_64\CPU;

final class Panic
{
    private static bool $alreadyPanicking = false;

    /** Print a message and stop the kernel. Never returns. */
    public static function now(string $message): never
    {
        // Stop here if printing the message panics too
        if (self::$alreadyPanicking) {
            CPU::haltForever();
        }
        self::$alreadyPanicking = true;

        // Use %s so a % in the message is not read as a format
        Kprintf::printf("PANIC: %s\n", $message);

        CPU::haltForever();
    }
}
