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
    // The first two words of every request ID
    public const COMMON_MAGIC_0 = 0xc7b1dd30df4c8b88;
    public const COMMON_MAGIC_1 = 0x0a82e883a194f07b;

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

    // The last two words of the memory map and HHDM request IDs
    public const MEMMAP_REQUEST_2 = 0x67cf3d9d378a806f;
    public const MEMMAP_REQUEST_3 = 0xe304acdfc50c3c62;
    public const HHDM_REQUEST_2 = 0x48dcf1cb8ad2b852;
    public const HHDM_REQUEST_3 = 0x63984e959a98244b;

    // Which word of a request holds the address of the answer (id[4], revision, response)
    public const RESPONSE_WORD = 5;

    // Memory map region types
    public const MEMMAP_USABLE = 0;
    public const MEMMAP_RESERVED = 1;
    public const MEMMAP_ACPI_RECLAIMABLE = 2;
    public const MEMMAP_ACPI_NVS = 3;
    public const MEMMAP_BAD_MEMORY = 4;
    public const MEMMAP_BOOTLOADER_RECLAIMABLE = 5;
    public const MEMMAP_EXECUTABLE_AND_MODULES = 6;
    public const MEMMAP_FRAMEBUFFER = 7;
    public const MEMMAP_RESERVED_MAPPED = 8;
}
