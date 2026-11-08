<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

use Polyvanthos\Lib\Kprintf;
use Polyvanthos\Lib\Panic;
use Polyvanthos\Mm\Heap;
use Polyvanthos\Mm\MemMap;
use Polyvanthos\Mm\PMM;

/** Checks that run at boot. The output goes to the serial console. */
final class SelfTest
{
    /** Prints test cases with the expected output next to each, for checking by eye. */
    public static function kprintf(): void
    {
        Kprintf::printf("--- kprintf selftest ---\n");

        Kprintf::printf("literal text\n"); // literal text
        Kprintf::printf("%%\n"); // %
        Kprintf::printf("%c%c%c\n", ord('a'), ord('b'), ord('c')); // abc
        Kprintf::printf("%s\n", 'hello'); // hello
        Kprintf::printf("%s\n", null); // (null)

        Kprintf::printf("%u\n", 0); // 0
        Kprintf::printf("%u\n", 1234567890); // 1234567890
        Kprintf::printf("%u\n", 4294967295); // 4294967295
        Kprintf::printf("%d\n", 0); // 0
        Kprintf::printf("%d\n", -1); // -1
        Kprintf::printf("%d\n", 2147483647); // 2147483647
        Kprintf::printf("%d\n", -2147483647 - 1); // -2147483648
        Kprintf::printf("%x\n", 0); // 0
        Kprintf::printf("%x\n", 255); // ff
        Kprintf::printf("%x\n", 0xDEADBEEF); // deadbeef
        Kprintf::printf("%p\n", 0x1000); // 0x1000

        Kprintf::printf("%lu\n", 0xFFFFFFFFFFFFFFFF); // 18446744073709551615
        Kprintf::printf("%lx\n", 0xFFFFFFFFFFFFFFFF); // ffffffffffffffff
        Kprintf::printf("%ld\n", -9223372036854775807 - 1); // -9223372036854775808
        Kprintf::printf("%llu\n", 0xFFFFFFFFFFFFFFFF); // 18446744073709551615
        Kprintf::printf("%d %ld %d\n", 1, 2, 3); // 1 2 3

        Kprintf::printf("--- end selftest ---\n");
    }

    /** Allocates and frees pages and panics if anything is wrong. Run after PMM::init. */
    public static function pmm(): void
    {
        Kprintf::printf("--- pmm selftest ---\n");

        // Allocate, check and free pages. Any failure panics with a reason.
        $before = PMM::freePageCount();
        $a = PMM::allocPage();
        $b = PMM::allocPage();
        $c = PMM::allocPage();
        if ($a === 0 || $b === 0 || $c === 0) {
            Panic::now('pmm selftest failed: alloc_page returned 0');
        }
        if ($a % PMM::PAGE_SIZE !== 0 || $b % PMM::PAGE_SIZE !== 0 || $c % PMM::PAGE_SIZE !== 0) {
            Panic::now('pmm selftest failed: alloc_page returned unaligned page');
        }
        if ($a === $b || $a === $c || $b === $c) {
            Panic::now('pmm selftest failed: alloc_page returned duplicate pages');
        }
        if (PMM::freePageCount() !== $before - 3) {
            Panic::now('pmm selftest failed: free_page_count incorrect after allocation');
        }
        $p = $a + MemMap::hhdmOffset();
        Mem::write64($p, 0x1122334455667788);
        if (Mem::read64($p) !== 0x1122334455667788) {
            Panic::now('pmm selftest failed: memory write/read failed');
        }
        PMM::freePage($b);
        $d = PMM::allocPage();
        if ($d !== $b) {
            Panic::now('pmm selftest failed: did not get expected page from search hint');
        }
        PMM::freePage($a);
        PMM::freePage($b);
        PMM::freePage($c);
        if (PMM::freePageCount() !== $before) {
            Panic::now('pmm selftest failed: free_page_count incorrect after freeing');
        }
        Kprintf::printf("  page 0x%lx\n", $a);
        Kprintf::printf("  page 0x%lx\n", $b);
        Kprintf::printf("  page 0x%lx\n", $c);
        Kprintf::printf("  page 0x%lx\n", $d);

        Kprintf::printf("--- end pmm selftest ---\n");
    }

    /** Allocates, writes to and frees heap memory and panics if anything is wrong. Run after Heap::init. */
    public static function heap(): void
    {
        Kprintf::printf("--- heap selftest ---\n");

        $before = Heap::freeBytes();

        if (Heap::kmalloc(0) !== 0) {
            Panic::now('heap selftest failed: kmalloc(0) returned memory');
        }

        $a = Heap::kmalloc(1);
        $b = Heap::kmalloc(100);
        $c = Heap::kmalloc(5000);
        if ($a === 0 || $b === 0 || $c === 0) {
            Panic::now('heap selftest failed: kmalloc returned 0');
        }
        if ((($a | $b | $c) & 15) !== 0) {
            Panic::now('heap selftest failed: address not 16-byte aligned');
        }
        if (Heap::freeBytes() >= $before) {
            Panic::now('heap selftest failed: free bytes did not go down');
        }

        // If two blocks overlapped, one fill would overwrite the other.
        self::fill($a, 1, 0xAA);
        self::fill($b, 100, 0xBB);
        self::fill($c, 5000, 0xCC);
        if (!self::holds($a, 1, 0xAA) || !self::holds($b, 100, 0xBB) || !self::holds($c, 5000, 0xCC)) {
            Panic::now('heap selftest failed: blocks overlap');
        }

        // The hole left by b is the first free box that fits, so it must be reused.
        Heap::kfree($b);
        $d = Heap::kmalloc(50);
        if ($d !== $b) {
            Panic::now('heap selftest failed: freed hole was not reused');
        }
        Heap::kfree($d);

        // Everything is free again, so the boxes must have merged back into one.
        Heap::kfree($a);
        Heap::kfree($c);
        if (Heap::freeBytes() !== $before) {
            Panic::now('heap selftest failed: free bytes not restored after merging');
        }

        if (Heap::kmalloc(64 * 1024 * 1024) !== 0) {
            Panic::now('heap selftest failed: oversize request succeeded');
        }

        Kprintf::printf("  a=%p b=%p c=%p\n", $a, $b, $c);
        Kprintf::printf("--- end heap selftest ---\n");
    }

    // Write $value into $count bytes, and check later that they all still hold it.
    private static function fill(u64 $address, u64 $count, u8 $value): void
    {
        for ($i = 0; $i < $count; $i++) {
            Mem::write8($address + $i, $value);
        }
    }

    private static function holds(u64 $address, u64 $count, u8 $value): bool
    {
        for ($i = 0; $i < $count; $i++) {
            if (Mem::read8($address + $i) !== $value) {
                return false;
            }
        }
        return true;
    }
}
