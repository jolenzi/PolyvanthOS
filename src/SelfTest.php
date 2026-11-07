<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos;

use Polyvanthos\Lib\Kprintf;

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
}
