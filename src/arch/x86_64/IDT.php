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
        foreach (range(0, 31) as $vector) {
            self::setGate($vector, ISR::stub($vector));
        }

        // A double fault happens when delivering an exception fails, often because the stack
        // is bad. It gets its own stack so it can still run.
        self::setGate(8, ISR::stub(8), GDT::DOUBLE_FAULT_IST);

        self::$pointer->limit = count(self::$idt) * IDTEntry::SIZE - 1;
        self::$pointer->base = Mem::addr(self::$idt);
        CPU::lidt(Mem::addr(self::$pointer));
    }

    // $ist is the emergency stack slot (1-7) to switch to, or 0 to keep the current stack.
    private static function setGate(int $vector, u64 $handler, int $ist = 0): void
    {
        $gate = self::$idt[$vector];
        $gate->offsetLow = $handler & 0xFFFF;
        $gate->selector = GDT::KERNEL_CODE_SELECTOR;
        $gate->ist = $ist;
        $gate->typeAttr = 0x8E; // present, ring 0, 64-bit interrupt gate
        $gate->offsetMid = ($handler >> 16) & 0xFFFF;
        $gate->offsetHigh = $handler >> 32;
        $gate->reserved = 0;
    }

    /**
     * Called by isr_common in ISR.S. #[Export] gives it the plain C name the assembly looks for.
     * Prints a crash report and stops. Exceptions don't return yet.
     */
    #[Export('exception_handler')]
    public static function handleException(InterruptFrame $frame): never
    {
        $vector = $frame->vector;
        $name = $vector < 32 ? self::EXCEPTION_NAMES[$vector] : 'Unknown';

        Kprintf::printf("CPU exception %lu: %s (error code 0x%lx)\n", $vector, $name, $frame->errorCode);
        Kprintf::printf("RIP 0x%lx  CS 0x%lx  RFLAGS 0x%lx  RSP 0x%lx  SS 0x%lx\n", $frame->rip, $frame->cs, $frame->rflags, $frame->rsp, $frame->ss);
        Kprintf::printf("RAX 0x%lx  RBX 0x%lx  RCX 0x%lx\n", $frame->rax, $frame->rbx, $frame->rcx);
        Kprintf::printf("RDX 0x%lx  RSI 0x%lx  RDI 0x%lx\n", $frame->rdx, $frame->rsi, $frame->rdi);
        Kprintf::printf("RBP 0x%lx  R8  0x%lx  R9  0x%lx\n", $frame->rbp, $frame->r8, $frame->r9);
        Kprintf::printf("R10 0x%lx  R11 0x%lx  R12 0x%lx\n", $frame->r10, $frame->r11, $frame->r12);
        Kprintf::printf("R13 0x%lx  R14 0x%lx  R15 0x%lx\n", $frame->r13, $frame->r14, $frame->r15);

        // Page fault: cr2 holds the address that was accessed
        if ($vector === 14) {
            Kprintf::printf("CR2 0x%lx\n", CPU::readCr2());
        }

        Panic::now('CPU exception');
    }
}
