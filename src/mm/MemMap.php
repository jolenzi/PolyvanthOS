<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Mm;

use Polyvanthos\Boot\Limine;
use Polyvanthos\Boot\MemMapResponse;
use Polyvanthos\Cast;
use Polyvanthos\Lib\Kprintf;

final class MemMap
{
    // Names for the log, indexed by Limine's region type number (0 to 8).
    #[Count(9)]
    private const REGION_NAMES = [
        'free',
        'reserved',
        'ACPI tables',
        'ACPI NVS',
        'bad RAM',
        'Limine (in use)',
        'kernel + modules',
        'framebuffer',
        'reserved (mapped)',
    ];

    private static u64 $hhdmOffset;

    /**
     * The HHDM offset: the virtual address where Limine mapped all of physical RAM.
     * Physical address P is reachable at (P + hhdmOffset).
     */
    public static function setHhdmOffset(u64 $offset): void
    {
        self::$hhdmOffset = $offset;
    }

    public static function hhdmOffset(): u64
    {
        return self::$hhdmOffset;
    }

    /** Print every region in Limine's memory map, then the total usable memory. */
    public static function printMemoryMap(MemMapResponse $memmap): void
    {
        $usableBytes = Cast::u64(0);
        for ($i = 0; $i < $memmap->entryCount; $i++) {
            $entry = $memmap->entry($i);
            Kprintf::printf(
                "[0x%lx - 0x%lx) %lu KiB  %s\n",
                $entry->base,
                $entry->base + $entry->length,
                $entry->length >> 10,
                self::typeName($entry->type),
            );
            if ($entry->type === Limine::MEMMAP_USABLE) {
                $usableBytes += $entry->length;
            }
        }
        // Only usable regions can be handed out later. A page is 4096 bytes (2^12).
        Kprintf::printf("Usable memory: %lu MiB (%lu pages)\n", $usableBytes >> 20, $usableBytes >> 12);
    }

    private static function typeName(u64 $type): string
    {
        return $type < count(self::REGION_NAMES) ? self::REGION_NAMES[$type] : 'unknown';
    }
}
