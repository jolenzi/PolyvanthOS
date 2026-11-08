<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyvanthos\Boot;

/** Limine's answer to the HHDM request. */
final class HHDMResponse
{
    public u64 $revision;
    public u64 $offset;
}
