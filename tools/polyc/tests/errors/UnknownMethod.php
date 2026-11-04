<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: has no method

declare(strict_types=1);

namespace Test;

final class UnknownMethod
{
    public static function main(): void
    {
        Host::nothing();
    }
}
