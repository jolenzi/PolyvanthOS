<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

/**
 * Explicit conversions between whole numbers, for the places where PHP has no way to say
 * "this is a u8". The compiler writes the C cast itself.
 *
 * Cast::u64(0) is also how a variable gets a type other than int: a variable takes the type of
 * the first value stored in it.
 */
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

    /** True if the number is not zero. */
    #[Intrinsic]
    public static function bool(int $value): bool
    {
        return false;
    }
}
