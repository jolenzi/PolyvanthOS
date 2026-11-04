<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/** A class constant. 'type' is filled in the first time the constant is used. */
final class ConstInfo
{
    public ?Type $type = null;
    /** The C text of the value, once known: a string or number, or the name of an array. */
    public ?string $c = null;
    /** Value of an integer constant that can be evaluated now, so it can be written for any C type. */
    public ?int $value = null;
    /** True for a value above the signed 64-bit range, which is stored as a negative PHP int. */
    public bool $big = false;
    /** The text a #define gets for a scalar constant. */
    public ?string $body = null;
    public bool $resolving = false;

    /**
     * @param array<string,mixed> $expr
     * @param array<string,list<array<string,mixed>>> $attrs
     */
    public function __construct(
        public readonly string $name,
        public readonly array $expr,
        public readonly string $vis,
        public readonly array $attrs,
        public readonly int $line,
    ) {
    }
}
