<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: ambiguous as a variadic argument

declare(strict_types=1);

namespace Test;

final class CharVariadic
{
    private static function show(mixed ...$args): void
    {
    }

    public static function main(): void
    {
        self::show(1, 'a');
    }
}
