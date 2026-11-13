<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: division by zero

declare(strict_types=1);

namespace Test;

final class DivideByZero
{
    public static function main(): void
    {
        $a = 10;
        Host::int($a / 0);
    }
}
