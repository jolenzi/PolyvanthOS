<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Test;

/** Explicit conversions. The compiler writes the C cast itself. */
final class Cast
{
    #[Intrinsic]
    public static function u8(int $value): u8
    {
        return 0;
    }

    #[Intrinsic]
    public static function u16(int $value): u16
    {
        return 0;
    }

    #[Intrinsic]
    public static function u32(int $value): u32
    {
        return 0;
    }

    #[Intrinsic]
    public static function u64(int $value): u64
    {
        return 0;
    }

    #[Intrinsic]
    public static function i8(int $value): i8
    {
        return 0;
    }

    #[Intrinsic]
    public static function i16(int $value): i16
    {
        return 0;
    }

    #[Intrinsic]
    public static function i32(int $value): i32
    {
        return 0;
    }

    #[Intrinsic]
    public static function i64(int $value): i64
    {
        return 0;
    }

    #[Intrinsic]
    public static function bool(int $value): bool
    {
        return false;
    }
}
