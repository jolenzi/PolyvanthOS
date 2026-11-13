<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: cannot put u64[] in a string

declare(strict_types=1);

namespace Test;

final class InterpolationArray
{
    private static function show(mixed ...$items): void
    {
        Host::format("items: {$items}");
    }

    public static function main(): void
    {
        self::show(1, 2);
    }
}
