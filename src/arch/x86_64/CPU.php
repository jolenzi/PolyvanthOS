<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/** CPU instructions. The methods marked #[Native] are in native/x86_64.h. */
#[Header('x86_64.h')]
final class CPU
{
    /** Stop this CPU for good. */
    public static function haltForever(): never
    {
        self::cli(); // No interrupts after this

        // An NMI can still wake hlt, so loop back to sleep
        while (true) {
            self::hlt();
        }
    }

    #[Native]
    public static function cli(): void
    {
    }

    #[Native]
    public static function hlt(): void
    {
    }
}
