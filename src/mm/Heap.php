<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Mm;

use Polyvanthos\Lib\Kprintf;
use Polyvanthos\Lib\Panic;
use Polyvanthos\Mem;

/**
 * The kernel heap: kmalloc and kfree for any size (the page allocator only hands out whole
 * pages). One 1 MiB strip of memory is cut into boxes. Each box has a 32-byte label (Block)
 * in front of it with its size, whether it is free, and links to the boxes on either side.
 *
 * kmalloc takes the first free box that fits and splits off the spare part. kfree marks the
 * box free and merges it with free neighbours, so the strip doesn't break into tiny boxes.
 *
 * The strip comes from allocContiguous. The HHDM maps RAM in order, so pages that are next
 * to each other physically are also next to each other in memory.
 */
final class Heap
{
    private const HEAP_PAGES = 256;
    private const MAGIC_USED = 0xA110CA7E;
    private const MAGIC_FREE = 0xF4EEF4EE;

    // $first is the first box. $end is one byte past the heap, for checking addresses in kfree.
    private static ?Block $first = null;
    private static u64 $end;

    /** Set up the kernel heap. Needs the page allocator to be running first. */
    public static function init(): void
    {
        // The pages are physical, so reach them through the HHDM. Start with one big free box.
        $phys = PMM::allocContiguous(self::HEAP_PAGES);
        if ($phys === 0) {
            Panic::now('Cannot allocate the heap');
        }
        $first = Block::at($phys + MemMap::hhdmOffset());
        self::$first = $first;
        self::$end = Mem::addr($first) + self::HEAP_PAGES * PMM::PAGE_SIZE;
        $first->size = self::HEAP_PAGES * PMM::PAGE_SIZE - Block::SIZE;
        $first->next = null;
        $first->prev = null;
        $first->magic = self::MAGIC_FREE;
        $first->isFree = 1;
        Kprintf::printf("Heap: %lu KiB at 0x%lx\n", (self::HEAP_PAGES * PMM::PAGE_SIZE) >> 10, $phys);
    }

    /**
     * Allocate $size bytes. Returns the address, which is 16-byte aligned, or 0 if $size is 0
     * or the heap is out of room.
     */
    public static function kmalloc(u64 $size): u64
    {
        // First fit: take the first free box that is big enough. If the leftover can hold a
        // label plus 16 bytes, split it off as a new free box.
        if ($size === 0) {
            return 0;
        }
        $size = self::roundUp16($size);
        for ($b = self::$first; $b !== null; $b = $b->next) {
            if ($b->isFree === 0 || $b->size < $size) {
                continue;
            }
            if ($b->size - $size >= Block::SIZE + 16) {
                $rest = Block::at(Mem::addr($b) + Block::SIZE + $size);
                $rest->size = $b->size - $size - Block::SIZE;
                $rest->isFree = 1;
                $rest->magic = self::MAGIC_FREE;
                $rest->prev = $b;
                $rest->next = $b->next;
                if ($b->next !== null) {
                    $b->next->prev = $rest;
                }
                $b->next = $rest;
                $b->size = $size;
            }
            $b->isFree = 0;
            $b->magic = self::MAGIC_USED;
            return Mem::addr($b) + Block::SIZE;
        }
        return 0;
    }

    /** Give memory from kmalloc back. Passing 0 does nothing. */
    public static function kfree(u64 $address): void
    {
        // Check the address, mark the box free, then merge it with the next box and the
        // previous box if they are free.
        if ($address === 0) {
            return;
        }
        if ($address < Mem::addr(self::$first) + Block::SIZE || $address >= self::$end) {
            Panic::now('kfree: pointer is not in the heap');
        }
        $b = Block::at($address - Block::SIZE);
        if ($b->magic !== self::MAGIC_USED) {
            Panic::now('kfree: bad pointer or double free');
        }
        $b->isFree = 1;
        $b->magic = self::MAGIC_FREE;
        if ($b->next !== null && $b->next->isFree !== 0) {
            $b->size += Block::SIZE + $b->next->size;
            $b->next = $b->next->next;
            if ($b->next !== null) {
                $b->next->prev = $b;
            }
        }
        if ($b->prev !== null && $b->prev->isFree !== 0) {
            $b->prev->size += Block::SIZE + $b->size;
            $b->prev->next = $b->next;
            if ($b->next !== null) {
                $b->next->prev = $b->prev;
            }
        }
    }

    /** Total bytes currently free in the heap (not counting block labels). */
    public static function freeBytes(): u64
    {
        // After everything is freed and merged this is back to its starting value.
        $total = u64(0);
        for ($b = self::$first; $b !== null; $b = $b->next) {
            if ($b->isFree !== 0) {
                $total += $b->size;
            }
        }
        return $total;
    }

    // Round up to a multiple of 16, so every label and every returned address stays aligned.
    private static function roundUp16(u64 $n): u64
    {
        return ($n + 15) & ~15;
    }
}
