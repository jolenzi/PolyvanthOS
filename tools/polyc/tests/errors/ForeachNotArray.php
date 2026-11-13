<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: foreach needs an array or a string, not

declare(strict_types=1);

namespace Test;

final class ForeachNotArray
{
    #[FixedArray('u8', 2)]
    private static array $bytes;

    private static function make(): int
    {
        return 1;
    }

    public static function main(): void
    {
        $number = 5;
        foreach ($number as $digit) {
            Host::int($digit);
        }
    }
}
