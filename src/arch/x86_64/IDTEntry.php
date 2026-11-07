<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/** One IDT entry (a "gate"). The handler address is split into 3 pieces. */
#[Packed, Size(16)]
final class IDTEntry
{
    public u16 $offsetLow; // address bits 0-15
    public u16 $selector; // code segment the handler runs in
    public u8 $ist; // special stack number, 0 = none (needs a TSS)
    public u8 $typeAttr; // gate type and flags
    public u16 $offsetMid; // address bits 16-31
    public u32 $offsetHigh; // address bits 32-63
    public u32 $reserved; // must be 0
}
