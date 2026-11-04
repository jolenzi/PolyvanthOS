<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: use intdiv

declare(strict_types=1);

namespace Test;

final class Division
{
    public static function main(): void
    {
        $a = 10;
        Host::int($a / 2);
    }
}
