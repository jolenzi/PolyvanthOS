<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: as {$name[index]}, with braces

declare(strict_types=1);

namespace Test;

final class InterpolationBareIndex
{
    private static function show(int ...$items): void
    {
        Host::format("first: $items[0]");
    }

    public static function main(): void
    {
        self::show(1, 2);
    }
}
