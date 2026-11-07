<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

use Polyvanthos\Arch\X86_64\CPU;
use Polyvanthos\Arch\X86_64\GDT;
use Polyvanthos\Boot\Limine;
use Polyvanthos\Drivers\Serial;
use Polyvanthos\Lib\Panic;

final class Kernel
{
    // Limine scans for these in order: start marker, requests, end marker.
    // The linker script keeps them together. Volatile because Limine writes into them, so the
    // compiler must not assume they still hold what we put there.
    #[FixedArray('u64', 4), Section('.limine_requests_start'), Used, Volatile]
    private static array $requestsStart = [
        Limine::REQUESTS_START_0,
        Limine::REQUESTS_START_1,
        Limine::REQUESTS_START_2,
        Limine::REQUESTS_START_3,
    ];

    #[FixedArray('u64', 3), Section('.limine_requests'), Used, Volatile]
    private static array $baseRevision = [
        Limine::BASE_REVISION_0,
        Limine::BASE_REVISION_1,
        Limine::BASE_REVISION,
    ];

    #[FixedArray('u64', 2), Section('.limine_requests_end'), Used, Volatile]
    private static array $requestsEnd = [
        Limine::REQUESTS_END_0,
        Limine::REQUESTS_END_1,
    ];

    /** The kernel entry point. Limine jumps here; the linker script names it as ENTRY. */
    #[Export('kmain')]
    public static function main(): never
    {
        Serial::init(Serial::DEFAULT_BAUD);
        Serial::puts("PolyvanthOS booting...\n");

        // Check Limine accepted our base revision. Needs serial, so it can print.
        if (self::$baseRevision[2] !== 0) {
            Panic::now('Limine base revision not supported');
        }

        GDT::init();

        SelfTest::kprintf();

        CPU::haltForever();
    }
}
