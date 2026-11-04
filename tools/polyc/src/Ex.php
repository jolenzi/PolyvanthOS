<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/** A compiled expression: its C text and its type. */
final class Ex
{
    /**
     * @param ?int $value the value, if this is a whole-number constant. Constants are written out
     *                    fresh for each C type they are used as, so no C conversion rules apply.
     */
    public function __construct(
        public readonly string $c,
        public readonly Type $t,
        public readonly ?int $value = null,
        public readonly bool $big = false,
    ) {
    }
}
