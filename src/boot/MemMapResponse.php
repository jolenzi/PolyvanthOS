<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Boot;

use Polyvanthos\Mem;

/** Limine's answer to the memory map request. Use MemMapResponse::at($address) to look at it. */
final class MemMapResponse
{
    public u64 $revision;
    public u64 $entryCount;
    public u64 $entries; // the address of an array of $entryCount pointers to MemMapEntry

    /** Region number $index. The array holds pointers, so each one is read from memory first. */
    public function entry(u64 $index): MemMapEntry
    {
        return MemMapEntry::at(Mem::read64($this->entries + $index * 8));
    }
}
