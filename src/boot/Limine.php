<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Boot;

/**
 * The Limine boot protocol: the numbers the kernel and the bootloader have to agree on.
 * A request is a block of 64-bit words in the kernel image. Limine finds it by its ID, which is
 * four magic words, and writes its answer's address into the last word.
 */
final class Limine
{
    // Limine scans for these in order: start marker, requests, end marker
    public const REQUESTS_START_0 = 0xf6b8f4b39de7d1ae;
    public const REQUESTS_START_1 = 0xfab91a6940fcb9cf;
    public const REQUESTS_START_2 = 0x785c6ed015d3e316;
    public const REQUESTS_START_3 = 0x181e920a7852b9d9;
    public const REQUESTS_END_0 = 0xadc0e0531bb10d03;
    public const REQUESTS_END_1 = 0x9572709f31764c62;

    // The base revision tag: two magic words, then the revision we want. Limine zeroes the
    // third word if it supports that revision.
    public const BASE_REVISION_0 = 0xf9562b2d5c95a6c8;
    public const BASE_REVISION_1 = 0x6a7b384944536bdc;
    public const BASE_REVISION = 3;
}
