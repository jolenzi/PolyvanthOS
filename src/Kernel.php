<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

use Polyvanthos\Arch\X86_64\CPU;
use Polyvanthos\Arch\X86_64\GDT;
use Polyvanthos\Arch\X86_64\IDT;
use Polyvanthos\Boot\HHDMResponse;
use Polyvanthos\Boot\Limine;
use Polyvanthos\Boot\MemMapResponse;
use Polyvanthos\Drivers\Serial;
use Polyvanthos\Lib\Kprintf;
use Polyvanthos\Lib\Panic;
use Polyvanthos\Mm\MemMap;
use Polyvanthos\Mm\PMM;

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

    // Ask Limine for the memory map and the HHDM offset. A request is the ID (4 words), the
    // revision, then the answer's address. Limine fills in the answer before kmain runs.
    #[FixedArray('u64', 6), Section('.limine_requests'), Used, Volatile]
    private static array $memmapRequest = [
        Limine::COMMON_MAGIC_0,
        Limine::COMMON_MAGIC_1,
        Limine::MEMMAP_REQUEST_2,
        Limine::MEMMAP_REQUEST_3,
        0,
        0,
    ];

    #[FixedArray('u64', 6), Section('.limine_requests'), Used, Volatile]
    private static array $hhdmRequest = [
        Limine::COMMON_MAGIC_0,
        Limine::COMMON_MAGIC_1,
        Limine::HHDM_REQUEST_2,
        Limine::HHDM_REQUEST_3,
        0,
        0,
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
        IDT::init();

        // If Limine didn't answer, the answer's address is still 0, so check before using it.
        // The HHDM offset is where Limine maps all physical RAM, so physical address P is
        // reachable at P + offset.
        $memmap = self::$memmapRequest[Limine::RESPONSE_WORD];
        $hhdm = self::$hhdmRequest[Limine::RESPONSE_WORD];
        if ($memmap === 0 || $hhdm === 0) {
            Panic::now('Limine did not provide a memory map');
        }
        MemMap::setHhdmOffset(HHDMResponse::at($hhdm)->offset);
        Kprintf::printf("HHDM offset: 0x%lx\n", MemMap::hhdmOffset());
        MemMap::printMemoryMap(MemMapResponse::at($memmap));

        // Start the page allocator (it needs the HHDM offset set above) and test it.
        PMM::init(MemMapResponse::at($memmap));
        SelfTest::pmm();

        SelfTest::kprintf();

        CPU::haltForever();
    }
}
