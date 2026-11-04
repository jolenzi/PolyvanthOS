<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only
// error: 'new' needs an allocator

declare(strict_types=1);

namespace Test;

final class NoAllocator
{
    public static function main(): void
    {
        $c = new Counter();
    }
}
