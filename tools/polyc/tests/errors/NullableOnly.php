<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: only classes and strings can be nullable

declare(strict_types=1);

namespace Test;

final class NullableOnly
{
    public static function bad(?int $x): void
    {
    }
}
