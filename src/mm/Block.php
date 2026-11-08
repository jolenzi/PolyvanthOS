<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Mm;

/**
 * The label in front of every box of heap memory. It is 32 bytes, so the space after it is
 * 16-byte aligned. 'magic' marks a box as used or free and catches bad pointers and double frees.
 * Use Block::at($address) to look at the label that sits at an address.
 */
#[Size(32)]
final class Block
{
    public u64 $size;
    public ?Block $next;
    public ?Block $prev;
    public u32 $magic;
    public u32 $isFree;
}
