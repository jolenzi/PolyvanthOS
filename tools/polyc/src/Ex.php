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
     * @param ?int $char  the byte, if this is a string literal of exactly one byte. Such a literal is a
     *                    string until it meets a whole number, and then it is that number ('a' is 97).
     * @param ?string $charC for a choice between two one-byte literals ($ok ? 'y' : 'n'): the C text that
     *                       picks the byte. Then $char is only the first literal's byte, a sign that this
     *                       is a character.
     */
    public function __construct(
        public readonly string $c,
        public readonly Type $t,
        public readonly ?int $value = null,
        public readonly bool $big = false,
        public readonly ?int $char = null,
        public readonly ?string $charC = null,
    ) {
    }
}
