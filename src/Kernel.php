<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

use Polyvanthos\Arch\X86_64\CPU;
use Polyvanthos\Boot\Limine;
use Polyvanthos\Drivers\Serial;

final class Kernel
{
    // Limine reads this to find out which protocol revision we want.
    #[FixedArray('u64', 3), Section('.data.limine'), Used, Volatile]
    private static array $baseRevision = [
        Limine::BASE_REVISION_0,
        Limine::BASE_REVISION_1,
        Limine::BASE_REVISION,
    ];

    /** The kernel entry point. Limine jumps here; the linker script names it as ENTRY. */
    #[Export('kmain')]
    public static function main(): never
    {
        Serial::init(Serial::DEFAULT_BAUD);
        Serial::puts("PolyvanthOS booting...\n");
        while (true) {
            CPU::hlt();
        }
    }
}
