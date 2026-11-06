<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

/**
 * Reading and writing memory at an address. Addresses are plain integers (u64), because this is
 * how the kernel sees memory: the page allocator hands out addresses, the heap hands out addresses.
 *
 * The C side is native/mem.h. Every access there is volatile.
 */
#[Native('mem.h')]
final class Mem
{
    public static function read8(u64 $address): u8
    {
        return 0;
    }

    public static function read16(u64 $address): u16
    {
        return 0;
    }

    public static function read32(u64 $address): u32
    {
        return 0;
    }

    public static function read64(u64 $address): u64
    {
        return 0;
    }

    public static function write8(u64 $address, u8 $value): void
    {
    }

    public static function write16(u64 $address, u16 $value): void
    {
    }

    public static function write32(u64 $address, u32 $value): void
    {
    }

    public static function write64(u64 $address, u64 $value): void
    {
    }

    /**
     * The address of an object, an array or a string. The compiler writes this one itself,
     * so it accepts any of those, whatever the parameter type says.
     */
    #[Intrinsic]
    public static function addr(u64 $value): u64
    {
        return 0;
    }

    /**
     * The address of $bytes bytes of scratch space on the stack, gone when the function returns.
     * $bytes must be a constant.
     */
    #[Intrinsic]
    public static function stack(int $bytes): u64
    {
        return 0;
    }
}
