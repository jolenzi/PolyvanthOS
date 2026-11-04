<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/**
 * A type in the PHP subset. Integers have a fixed width. Class types are pointers in C: a
 * class is a struct and an object is the address of one.
 */
final class Type
{
    /** Names that always mean the fixed-width integers, wherever they appear. */
    public const INT_NAMES = [
        'u8' => [8, false], 'u16' => [16, false], 'u32' => [32, false], 'u64' => [64, false],
        'i8' => [8, true], 'i16' => [16, true], 'i32' => [32, true], 'i64' => [64, true],
        'int' => [64, true],
    ];

    private function __construct(
        public readonly string $kind,        // int bool string void never null mixed class array
        public readonly int $bits = 0,       // int only
        public readonly bool $signed = false,
        public readonly ?string $class = null,
        public readonly bool $nullable = false,
        public readonly ?Type $elem = null,  // array only
        public readonly ?int $length = null, // array only, null when unknown (variadic)
    ) {
    }

    public static function int(int $bits, bool $signed): self
    {
        return new self('int', $bits, $signed);
    }

    public static function named(string $name): ?self
    {
        if (isset(self::INT_NAMES[$name])) {
            return self::int(...self::INT_NAMES[$name]);
        }
        return match ($name) {
            'bool', 'string', 'void', 'never', 'null', 'mixed' => new self($name),
            default => null,
        };
    }

    public static function simple(string $kind): self
    {
        return new self($kind);
    }

    public static function ofClass(string $fqcn, bool $nullable = false): self
    {
        return new self('class', class: $fqcn, nullable: $nullable);
    }

    public static function array(Type $elem, ?int $length): self
    {
        return new self('array', elem: $elem, length: $length);
    }

    public function withNullable(): self
    {
        return match ($this->kind) {
            'class' => self::ofClass($this->class, true),
            'string' => new self('string', nullable: true),
            default => $this,
        };
    }

    public function isInt(): bool
    {
        return $this->kind === 'int';
    }

    public function isU64(): bool
    {
        return $this->kind === 'int' && $this->bits === 64 && !$this->signed;
    }

    public function isClass(): bool
    {
        return $this->kind === 'class';
    }

    public function isPointerLike(): bool
    {
        return $this->kind === 'class' || $this->kind === 'string' || $this->kind === 'null';
    }

    public function equals(Type $o): bool
    {
        return $this->kind === $o->kind && $this->bits === $o->bits && $this->signed === $o->signed
            && $this->class === $o->class;
    }

    /** The name used in messages. */
    public function name(): string
    {
        return match ($this->kind) {
            'int' => ($this->signed ? 'i' : 'u') . $this->bits,
            'class' => ($this->nullable ? '?' : '') . $this->class,
            'array' => $this->elem->name() . '[]',
            'string' => ($this->nullable ? '?' : '') . 'string',
            default => $this->kind,
        };
    }

    /** The C type, given a function that turns a class name into its C struct name. */
    public function c(callable $structName): string
    {
        return match ($this->kind) {
            'int' => ($this->signed ? 'int' : 'uint') . $this->bits . '_t',
            'bool' => 'bool',
            'string' => 'const char *',
            'void', 'never' => 'void',
            'mixed' => 'uint64_t',
            'class' => $structName($this->class) . ' *',
            'null' => 'void *',
            'array' => $this->elem->c($structName),
        };
    }
}
