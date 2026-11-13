<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Mm;

use Polyvanthos\Boot\Limine;
use Polyvanthos\Boot\MemMapResponse;
use Polyvanthos\Lib\Kprintf;
use Polyvanthos\Lib\Panic;
use Polyvanthos\Mem;

/**
 * The physical memory manager (PMM) tracks which 4 KiB pages of RAM are free, using a bitmap:
 * one bit per page, 1 = taken, 0 = free. 1 GiB of RAM needs only 32 KiB of bitmap.
 *
 * Page N is at physical address N << 12. Its bit is in byte N >> 3, at position N & 7.
 *
 * The bitmap lives in RAM too, so it is placed in the first usable region that fits and its
 * own pages are marked taken. We reach it through the HHDM: physical address P is the
 * address P + hhdmOffset.
 */
final class PMM
{
    // One page of physical memory is 4096 bytes (2^12).
    public const PAGE_SIZE = 4096;
    public const PAGE_SHIFT = 12;

    // The bitmap's address (virtual, in the HHDM). One bit per page.
    private static u64 $bitmap;
    private static u64 $totalPages;
    private static u64 $freePages;
    // Where allocPage starts looking.
    private static u64 $searchHint;

    /** Set up the allocator from Limine's memory map. Needs the HHDM offset to be set first. */
    public static function init(MemMapResponse $memmap): void
    {
        // The bitmap covers every page up to the end of the highest usable region. Reserved
        // regions far above (framebuffer, device memory) are ignored so it stays small.
        $highestEnd = u64(0);
        for ($i = 0; $i < $memmap->entryCount; $i++) {
            $entry = $memmap->entry($i);
            if ($entry->type === Limine::MEMMAP_USABLE) {
                $end = $entry->base + $entry->length;
                if ($end > $highestEnd) {
                    $highestEnd = $end;
                }
            }
        }
        self::$totalPages = $highestEnd >> self::PAGE_SHIFT;

        // 8 pages per byte. Adding (divisor - 1) before shifting rounds up instead of down.
        $bitmapBytes = (self::$totalPages + 7) >> 3;
        $bitmapPages = ($bitmapBytes + self::PAGE_SIZE - 1) >> self::PAGE_SHIFT;

        // Put the bitmap in the first usable region that is big enough. That address is
        // physical, so add the HHDM offset before touching it.
        $bitmapPhys = u64(0);
        $found = false;
        for ($i = 0; $i < $memmap->entryCount; $i++) {
            $entry = $memmap->entry($i);
            if ($entry->type === Limine::MEMMAP_USABLE && $entry->length >= $bitmapPages * self::PAGE_SIZE) {
                $bitmapPhys = $entry->base;
                $found = true;
                self::$bitmap = $bitmapPhys + MemMap::hhdmOffset();
                break;
            }
        }
        if (!$found) {
            Panic::now('No room for the page bitmap');
        }

        // Start with every page taken, then free only the ones we know are usable. Anything
        // we forget about stays safe. (A plain loop: there is no memset in the kernel.)
        for ($i = 0; $i < $bitmapBytes; $i++) {
            Mem::write8(self::$bitmap + $i, 0xFF);
        }
        self::$freePages = 0;
        self::$searchHint = 0;

        // Free the usable pages. Page 0 stays taken, because allocPage returns 0 to mean
        // "out of memory".
        for ($i = 0; $i < $memmap->entryCount; $i++) {
            $entry = $memmap->entry($i);
            if ($entry->type === Limine::MEMMAP_USABLE) {
                $startPage = $entry->base >> self::PAGE_SHIFT;
                $endPage = ($entry->base + $entry->length) >> self::PAGE_SHIFT;
                for ($page = $startPage; $page < $endPage; $page++) {
                    if ($page === 0) {
                        continue;
                    }
                    self::markFree($page);
                    self::$freePages++;
                }
            }
        }

        // The bitmap's own pages are inside a usable region, so take them back out. This has
        // to run after the loop above, or it would be undone.
        $firstPage = $bitmapPhys >> self::PAGE_SHIFT;
        for ($page = $firstPage; $page < $firstPage + $bitmapPages; $page++) {
            self::markUsed($page);
            self::$freePages--;
        }
        Kprintf::printf("PMM: %lu pages free, bitmap at 0x%lx\n", self::$freePages, $bitmapPhys);
    }

    /**
     * Hand out one free page. Returns its PHYSICAL address (always a multiple of 4096),
     * or 0 if there is no memory left. Page 0 is never handed out, so 0 is safe as "failed".
     */
    public static function allocPage(): u64
    {
        // Scan from the hint to the end, then from the start up to the hint.
        for ($page = self::$searchHint; $page < self::$totalPages; $page++) {
            if (!self::isUsed($page)) {
                self::markUsed($page);
                self::$freePages--;
                self::$searchHint = $page + 1;
                return $page << self::PAGE_SHIFT;
            }
        }
        for ($page = 0; $page < self::$searchHint; $page++) {
            if (!self::isUsed($page)) {
                self::markUsed($page);
                self::$freePages--;
                self::$searchHint = $page + 1;
                return $page << self::PAGE_SHIFT;
            }
        }

        return 0;
    }

    /** Give a page back. $phys must be an address that allocPage returned. */
    public static function freePage(u64 $phys): void
    {
        // Bad input panics, so a double free is caught at the call that made it.
        if (($phys & (self::PAGE_SIZE - 1)) !== 0) {
            Panic::now('free_page: unaligned');
        }
        $page = $phys >> self::PAGE_SHIFT;
        if ($page >= self::$totalPages) {
            Panic::now('free_page: out of range');
        }
        if (!self::isUsed($page)) {
            Panic::now('free_page: double free');
        }
        self::markFree($page);
        self::$freePages++;
        if ($page < self::$searchHint) {
            self::$searchHint = $page;
        }
    }

    /**
     * Hand out $count free pages that are next to each other. Returns the PHYSICAL address of
     * the first one, or 0 if there is no run that long (or $count is 0).
     */
    public static function allocContiguous(u64 $count): u64
    {
        // Look for $count free pages in a row. A taken page resets the count.
        if ($count === 0) {
            return 0;
        }

        $runStart = u64(0);
        $runLength = u64(0);
        for ($page = u64(0); $page < self::$totalPages; $page++) {
            if (self::isUsed($page)) {
                $runLength = 0;
                continue;
            }
            if ($runLength === 0) {
                $runStart = $page;
            }
            $runLength++;
            if ($runLength === $count) {
                for ($i = 0; $i < $count; $i++) {
                    self::markUsed($runStart + $i);
                }
                self::$freePages -= $count;
                return $runStart << self::PAGE_SHIFT;
            }
        }

        return 0;
    }

    /** Give back a run from allocContiguous, with the same $count. */
    public static function freeContiguous(u64 $phys, u64 $count): void
    {
        // freePage already checks alignment, range and double frees.
        for ($i = 0; $i < $count; $i++) {
            self::freePage($phys + $i * self::PAGE_SIZE);
        }
    }

    /** How many pages are free right now. */
    public static function freePageCount(): u64
    {
        return self::$freePages;
    }

    // The bit twiddling lives here once, so the rest of the code can read like English.
    private static function isUsed(u64 $page): bool
    {
        return (Mem::read8(self::$bitmap + ($page >> 3)) & (1 << ($page & 7))) !== 0;
    }

    private static function markUsed(u64 $page): void
    {
        $byte = self::$bitmap + ($page >> 3);
        Mem::write8($byte, Mem::read8($byte) | (1 << ($page & 7)));
    }

    private static function markFree(u64 $page): void
    {
        $byte = self::$bitmap + ($page >> 3);
        Mem::write8($byte, Mem::read8($byte) & ~(1 << ($page & 7)));
    }
}
