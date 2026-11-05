<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Boot;

/**
 * The Limine boot protocol: the numbers the kernel and the bootloader have to agree on.
 * Limine looks for a block of 64-bit words in the kernel image and reads the revision it wants.
 */
final class Limine
{
    // The base revision tag: two magic words, then the revision we want. Limine zeroes the
    // third word if it supports that revision.
    public const BASE_REVISION_0 = 0xf9562b2d5c95a6c8;
    public const BASE_REVISION_1 = 0x6a7b384944536bdc;
    public const BASE_REVISION = 3;
}
