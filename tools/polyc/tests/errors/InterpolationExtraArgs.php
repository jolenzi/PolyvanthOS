<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: cannot be followed by more arguments

declare(strict_types=1);

namespace Test;

final class InterpolationExtraArgs
{
    public static function main(): void
    {
        $count = 3;
        Host::format("items: $count", 4);
    }
}
