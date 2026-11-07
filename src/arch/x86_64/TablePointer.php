<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Arch\X86_64;

/**
 * What lgdt and lidt read: the table size minus 1, then its address. Packed so there is no padding
 * between the two fields.
 */
#[Packed, Size(10)]
final class TablePointer
{
    public u16 $limit;
    public u64 $base;
}
