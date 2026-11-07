<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/** The assembly stubs in ISR.S. Native: the table is defined in assembly. */
#[Native('x86_64.h')]
final class ISR
{
    /** The address of the stub for a CPU exception vector (0-31). */
    public static function stub(int $vector): u64
    {
        return 0;
    }
}
