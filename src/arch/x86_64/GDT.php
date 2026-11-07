<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

use Polyvanthos\Mem;

final class GDT
{
    // Selectors: the byte offset of each entry in the GDT
    public const KERNEL_CODE_SELECTOR = 0x08; // entry 1
    public const KERNEL_DATA_SELECTOR = 0x10; // entry 2

    // Entry 0 is null (required), 1 is kernel code, 2 is kernel data.
    // Entry N is at byte offset N * 8, which is where the selectors come from.
    #[FixedArray('u64', 3), Align(8)]
    private static array $gdt;

    private static TablePointer $pointer;

    /** Load our own GDT, replacing Limine's. */
    public static function init(): void
    {
        // Base and limit are ignored in 64-bit mode, so only the access byte (bits 40-47)
        // and flags (bits 52-55) are set.
        // 0x9A = present, ring 0, code, executable, readable. Bit 53 (L) = 64-bit code.
        // 0x92 = present, ring 0, data, writable.
        self::$gdt[1] = (0x9A << 40) | (1 << 53);
        self::$gdt[2] = 0x92 << 40;

        self::$pointer->limit = count(self::$gdt) * 8 - 1;
        self::$pointer->base = Mem::addr(self::$gdt);
        CPU::lgdt(Mem::addr(self::$pointer));

        // lgdt doesn't touch the segment registers, so reload them from the new table.
        CPU::loadDataSegments(self::KERNEL_DATA_SELECTOR);
        CPU::loadCodeSegment(self::KERNEL_CODE_SELECTOR);
    }
}
