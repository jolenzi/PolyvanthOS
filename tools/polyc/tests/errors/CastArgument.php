<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: u8() needs a whole number

declare(strict_types=1);

namespace Test;

final class CastArgument
{
    public static function main(): void
    {
        Host::int(u8('ab'));
    }
}
