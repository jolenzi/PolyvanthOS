<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: casts are not supported

declare(strict_types=1);

namespace Test;

final class PhpCast
{
    public static function main(): void
    {
        $a = 5;
        Host::int((int) $a);
    }
}
