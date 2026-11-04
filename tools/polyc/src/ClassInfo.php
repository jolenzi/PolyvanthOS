<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/** Everything the compiler knows about one class. */
final class ClassInfo
{
    /** @var array<string,ConstInfo> */
    public array $consts = [];
    /** @var array<string,PropInfo> */
    public array $props = [];
    /** @var array<string,PropInfo> */
    public array $staticProps = [];
    /** @var array<string,MethodInfo> */
    public array $methods = [];
    /** Set when some code uses "new" on this class, so its allocating constructor is emitted. */
    public bool $needsNew = false;

    /** @param array<string,list<array<string,mixed>>> $attrs */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $short,
        public readonly string $mangled,
        public readonly string $file,
        public readonly int $line,
        public readonly array $attrs,
    ) {
    }

    /** A class with instance properties is a struct in C, and objects are pointers to it. */
    public function isStruct(): bool
    {
        return $this->props !== [];
    }

    /**
     * The C name of a static property. The extra "s" keeps it apart from a method of the same
     * name, which PHP allows.
     */
    public function staticName(string $property): string
    {
        return $this->mangled . '_s_' . $property;
    }
}
