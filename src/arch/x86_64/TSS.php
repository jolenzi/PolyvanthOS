<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/**
 * The TSS (Task State Segment). In 64-bit mode we only use it for the IST: a list of emergency
 * stacks that an IDT gate can ask the CPU to switch to. The CPU reads it by byte offset, so it
 * must be packed (rsp[0] sits at byte 4).
 */
#[Packed, Size(104)]
final class TSS
{
    public u32 $reserved0;
    #[FixedArray('u64', 3)]
    public array $rsp;
    public u64 $reserved1;
    #[FixedArray('u64', 7)]
    public array $ist;
    public u64 $reserved2;
    public u16 $reserved3;
    public u16 $iopb;
}
