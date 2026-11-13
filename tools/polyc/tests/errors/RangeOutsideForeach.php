<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: range() can only be the list of a foreach

declare(strict_types=1);

namespace Test;

final class RangeOutsideForeach
{
    #[FixedArray('u8', 2)]
    private static array $bytes;

    private static function make(): int
    {
        return 1;
    }

    public static function main(): void
    {
        $list = range(1, 3);
        Host::int($list);
    }
}
