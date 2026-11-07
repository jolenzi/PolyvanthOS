<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

use Polyvanthos\Lib\Kprintf;
use Polyvanthos\Lib\Panic;
use Polyvanthos\Mem;

final class IDT
{
    #[Count(32)]
    private const EXCEPTION_NAMES = [
        'Divide error',
        'Debug',
        'NMI',
        'Breakpoint',
        'Overflow',
        'Bound range exceeded',
        'Invalid opcode',
        'Device not available',
        'Double fault',
        'Coprocessor segment overrun',
        'Invalid TSS',
        'Segment not present',
        'Stack-segment fault',
        'General protection fault',
        'Page fault',
        'Reserved',
        'x87 floating-point exception',
        'Alignment check',
        'Machine check',
        'SIMD floating-point exception',
        'Virtualization exception',
        'Control protection exception',
        'Reserved',
        'Reserved',
        'Reserved',
        'Reserved',
        'Reserved',
        'Reserved',
        'Hypervisor injection exception',
        'VMM communication exception',
        'Security exception',
        'Reserved',
    ];

    // 256 vectors: 0-31 are CPU exceptions, the rest are for hardware interrupts later.
    // Unused entries stay zero, which means "not present".
    #[FixedArray(IDTEntry::class, 256), Align(16)]
    private static array $idt;

    private static TablePointer $pointer;

    /** Load the IDT so CPU exceptions (divide by zero, page fault, ...) reach our handler. */
    public static function init(): void
    {
        for ($i = 0; $i < 32; ++$i) {
            self::setGate($i, ISR::stub($i));
        }

        self::$pointer->limit = count(self::$idt) * IDTEntry::SIZE - 1;
        self::$pointer->base = Mem::addr(self::$idt);
        CPU::lidt(Mem::addr(self::$pointer));
    }

    private static function setGate(int $vector, u64 $handler): void
    {
        $gate = self::$idt[$vector];
        $gate->offsetLow = $handler & 0xFFFF;
        $gate->selector = GDT::KERNEL_CODE_SELECTOR;
        $gate->ist = 0;
        $gate->typeAttr = 0x8E; // present, ring 0, 64-bit interrupt gate
        $gate->offsetMid = ($handler >> 16) & 0xFFFF;
        $gate->offsetHigh = $handler >> 32;
        $gate->reserved = 0;
    }

    /**
     * Called by isr_common in ISR.S. #[Export] gives it the plain C name the assembly looks for.
     * Exceptions don't return yet, so nothing is saved or restored.
     */
    #[Export('exception_handler')]
    public static function handleException(u64 $vector, u64 $errorCode): never
    {
        $name = $vector < 32 ? self::EXCEPTION_NAMES[$vector] : 'Unknown';
        Kprintf::printf("CPU exception %lu: %s (error code 0x%lx)\n", $vector, $name, $errorCode);
        Panic::now('CPU exception');
    }
}
