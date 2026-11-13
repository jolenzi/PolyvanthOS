<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Test;

/** Printing for the tests. The C side is in native/host.h. */
#[Native('host.h')]
final class Host
{
    public static function int(int $v): void
    {
    }

    public static function uint(u64 $v): void
    {
    }

    public static function hex(u64 $v): void
    {
    }

    public static function str(string $s): void
    {
    }

    public static function nl(): void
    {
    }

    /** A small printf that follows Kprintf's rules, so the tests can use string interpolation. */
    #[Format]
    public static function format(string $fmt, mixed ...$args): void
    {
    }

    public static function alloc(u64 $size): u64
    {
        return 0;
    }
}
