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
    public const TSS_SELECTOR = 0x18; // entries 3 and 4 (a TSS takes two slots)

    // Which IST slot (1-7) holds the emergency stack for double faults
    public const DOUBLE_FAULT_IST = 1;

    private const STACK_SIZE = 16384; // 16 KiB

    // Entry 0 is null (required), 1 is kernel code, 2 is kernel data.
    // Entry N is at byte offset N * 8, which is where the selectors come from.
    // The TSS descriptor is 16 bytes, so it takes slots 3 and 4.
    #[FixedArray('u64', 5), Align(8)]
    private static array $gdt;

    // Emergency stack for double faults, so a broken stack can't cause a triple fault.
    #[FixedArray('u8', self::STACK_SIZE), Align(16)]
    private static array $doubleFaultStack;

    private static TSS $tss;
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

        // IST slot 1 is ist[0]. Stacks grow down, so point at the end of the array.
        self::$tss->ist[0] = Mem::addr(self::$doubleFaultStack) + self::STACK_SIZE;
        // An offset past the end of the TSS means "no I/O permission bitmap".
        self::$tss->iopb = TSS::SIZE;

        // The TSS descriptor is two 64-bit halves. Unlike code and data, the base and limit
        // matter here. Low half: limit 0-15, base 0-23, access 0x89 (present, 64-bit TSS),
        // limit 16-19, flags, base 24-31. High half: base 32-63.
        $tss = Mem::addr(self::$tss);
        $limit = TSS::SIZE - 1;
        self::$gdt[3] = ($limit & 0xFFFF)
            | (($tss & 0xFFFFFF) << 16)
            | (0x89 << 40)
            | ((($limit >> 16) & 0xF) << 48)
            | (($tss & 0xFF000000) << 32);
        self::$gdt[4] = ($tss >> 32) & 0xFFFFFFFF;

        self::$pointer->limit = count(self::$gdt) * 8 - 1;
        self::$pointer->base = Mem::addr(self::$gdt);
        CPU::lgdt(Mem::addr(self::$pointer));

        // Tell the CPU where the TSS is. It marks the entry busy, so the table must stay writable.
        CPU::ltr(self::TSS_SELECTOR);

        // lgdt doesn't touch the segment registers, so reload them from the new table.
        CPU::loadDataSegments(self::KERNEL_DATA_SELECTOR);
        CPU::loadCodeSegment(self::KERNEL_CODE_SELECTOR);
    }
}
