<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/**
 * The registers saved by ISR.S, in stack order (lowest address first).
 * Fields are in the reverse of the push order, because the stack grows down.
 */
#[Size(176)]
final class InterruptFrame
{
    // Pushed by isr_common
    public u64 $r15, $r14, $r13, $r12, $r11, $r10, $r9, $r8;
    public u64 $rbp, $rdi, $rsi, $rdx, $rcx, $rbx, $rax;
    // Pushed by the stub
    public u64 $vector, $errorCode;
    // Pushed by the CPU
    public u64 $rip, $cs, $rflags, $rsp, $ss;
}
