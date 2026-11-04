<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: must return a value

declare(strict_types=1);

namespace Test;

final class NoReturnValue
{
    public static function value(): int
    {
        return;
    }
}
