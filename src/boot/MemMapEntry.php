<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Boot;

/** One region of the memory map. */
final class MemMapEntry
{
    public u64 $base;
    public u64 $length;
    public u64 $type;
}
