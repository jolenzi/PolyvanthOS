<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: cannot use string where i64 is expected

declare(strict_types=1);

namespace Test;

final class WrongType
{
    public static function main(): void
    {
        Host::int('text');
    }
}
