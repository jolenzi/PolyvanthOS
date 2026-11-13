<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: references are not supported

declare(strict_types=1);

namespace Test;

final class ForeachReference
{
    #[FixedArray('u8', 2)]
    private static array $bytes;

    private static function make(): int
    {
        return 1;
    }

    public static function main(): void
    {
        foreach (self::$bytes as &$byte) {
            Host::int($byte);
        }
    }
}
