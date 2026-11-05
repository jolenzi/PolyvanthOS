<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/**
 * x86 port I/O. Arch-specific: other architectures provide their own equivalent (for example
 * memory-mapped I/O on ARM) behind the same method names.
 *
 * The C side is native/x86_64.h.
 */
#[Native('x86_64.h')]
final class Port
{
    public static function outb(u16 $port, u8 $value): void
    {
    }

    public static function inb(u16 $port): u8
    {
        return 0;
    }
}
