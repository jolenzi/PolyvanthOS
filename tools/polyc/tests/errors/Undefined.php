<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: used before it gets a value

declare(strict_types=1);

namespace Test;

final class Undefined
{
    public static function main(): void
    {
        Host::int($missing);
    }
}
