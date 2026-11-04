<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Test;

/** Raw memory access, the same shape as the kernel's Mem class. */
#[Native('host.h')]
final class Mem
{
    public static function read8(u64 $address): u8
    {
        return 0;
    }

    public static function write8(u64 $address, u8 $value): void
    {
    }

    #[Intrinsic]
    public static function addr(u64 $value): u64
    {
        return 0;
    }

    #[Intrinsic]
    public static function stack(int $bytes): u64
    {
        return 0;
    }
}
