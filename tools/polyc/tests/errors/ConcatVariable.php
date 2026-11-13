<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: joins strings at build time

declare(strict_types=1);

namespace Test;

final class ConcatVariable
{
    public static function main(): void
    {
        $name = 'x';
        Host::str('a' . $name);
    }
}
