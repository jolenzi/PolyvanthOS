<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: can only be the first argument of a function marked #[Format]

declare(strict_types=1);

namespace Test;

final class InterpolationOutsideFormat
{
    public static function main(): void
    {
        $count = 3;
        Host::str("items: $count");
    }
}
