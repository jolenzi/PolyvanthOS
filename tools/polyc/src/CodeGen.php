<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/**
 * Turns every class of a Program into C.
 *
 * The output is one header, polyc.h, with everything that is shared (struct layouts, constants,
 * prototypes), and one .c file per source file with the function bodies. Every .c file includes
 * the header, so the order of classes never matters.
 */
final class CodeGen
{
    private const C_WORDS = [
        'auto', 'break', 'case', 'char', 'const', 'continue', 'default', 'do', 'double', 'else', 'enum',
        'extern', 'float', 'for', 'goto', 'if', 'inline', 'int', 'long', 'register', 'restrict', 'return',
        'short', 'signed', 'sizeof', 'static', 'struct', 'switch', 'typedef', 'union', 'unsigned', 'void',
        'volatile', 'while', 'bool', 'true', 'false', 'NULL', 'this', 'asm', 'typeof', 'alignas', 'alignof',
        'main', 'errno',
    ];

    /** @var array<string,array{file:string,protos:list<string>,statics:list<string>,funcs:list<string>}> */
    private array $units = [];
    /** @var list<string> */
    private array $headerProtos = [];
    /** @var list<string> */
    private array $headerExterns = [];
    /** @var array<string,true> */
    private array $newDone = [];

    public function __construct(private readonly Program $program)
    {
    }

    /** @return array<string,string> output path => contents */
    public function generate(): array
    {
        $done = [];
        do {
            $pending = array_diff_key($this->program->all(), $done);
            foreach ($pending as $fqcn => $class) {
                $done[$fqcn] = true;
                $this->compileClass($class);
            }
        } while ($pending !== []);

        foreach ($this->program->all() as $class) {
            if ($class->needsNew) {
                $this->compileNew($class);
            }
        }

        $files = ['polyc.h' => $this->header()];
        foreach ($this->units as $path => $unit) {
            $files[preg_replace('/\.php$/', '.c', $path)] = $this->unitText($unit);
        }
        return $files;
    }

    // ---------------------------------------------------------------- names and small pieces

    /** A C-safe name for a PHP variable or parameter. */
    public function ident(string $name): string
    {
        return in_array($name, self::C_WORDS, true) ? $name . '_' : $name;
    }

    /** The C struct name for a class that has instance properties. */
    public function structName(string $fqcn): string
    {
        $class = $this->program->find($fqcn);
        if (!$class->isStruct()) {
            throw new CompileError("class {$class->fqcn} has no properties, so it cannot be used as a type", $class->file, $class->line);
        }
        return $class->mangled;
    }

    /** The C type followed by a space (or a star), ready to put a name after. */
    public function cType(Type $type): string
    {
        $c = $type->c(fn (string $fqcn): string => $this->structName($fqcn));
        return str_ends_with($c, '*') ? $c : $c . ' ';
    }

    /** Remove one pair of parentheses that wraps all of $c, as in "(a == b)". */
    public function unwrap(string $c): string
    {
        if ($c === '' || $c[0] !== '(' || !str_ends_with($c, ')')) {
            return $c;
        }
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($c); $i < $n; $i++) {
            $ch = $c[$i];
            if ($quote !== null) {
                $i += $ch === '\\' ? 1 : 0;
                $quote = $ch === $quote ? null : $quote;
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && --$depth === 0) {
                return $i === $n - 1 ? substr($c, 1, -1) : $c;
            }
        }
        return $c;
    }

    public function cString(string $s): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            $o = ord($ch);
            $out .= match (true) {
                $ch === '"' => '\\"',
                $ch === '\\' => '\\\\',
                $ch === '?' => '\\?',
                $ch === "\n" => '\\n',
                $o >= 0x20 && $o < 0x7F => $ch,
                default => sprintf('\\%03o', $o),
            };
        }
        return '"' . $out . '"';
    }

    public function signature(ClassInfo $class, MethodInfo $m): string
    {
        $params = [];
        if (!$m->static) {
            $params[] = $class->mangled . ' *this';
        }
        foreach ($m->params as $p) {
            $name = $this->ident($p->name);
            $params[] = $p->variadic
                ? "size_t {$name}_count __attribute__((unused)), const uint64_t *$name"
                : $this->cType($p->type) . $name;
        }
        $internal = $m->vis !== 'public' && $m->export === null;
        return ($internal ? 'static ' : '')
            . ($m->ret->kind === 'never' ? '__attribute__((noreturn)) ' : '')
            . $this->cType($m->ret) . $m->cname . '(' . ($params === [] ? 'void' : implode(', ', $params)) . ')';
    }

    public function defaultValue(ClassInfo $class, ParamInfo $p): string
    {
        return (new FunctionGen($this, $this->program, $class))->initializer($p->default, $p->type);
    }

    /** Work out the value and type of a class constant, once. */
    public function constant(ClassInfo $class, string $name, array $at): ConstInfo
    {
        $const = $class->consts[$name];
        if ($const->c !== null) {
            return $const;
        }
        if ($const->resolving) {
            throw new CompileError("constant {$class->short}::$name refers to itself", $class->file, $at['line'] ?? $const->line);
        }
        $const->resolving = true;
        $gen = new FunctionGen($this, $this->program, $class);
        $cname = $class->mangled . '_' . $name;
        if ($const->expr['k'] === 'arr') {
            $const->type = $gen->arrayLiteralType($const->expr);
            $const->c = $cname;
        } else {
            $ex = $gen->constant($const->expr);
            if (!in_array($ex->t->kind, ['int', 'bool', 'string'], true)) {
                throw new CompileError("a constant must be a whole number, a bool, a string or an array, not {$ex->t->name()}", $class->file, $const->line);
            }
            $const->type = $ex->t;
            $const->value = $ex->value ?? ($ex->t->isInt() ? $this->program->foldInt($const->expr, $class) : null);
            $const->big = $ex->big;
            $const->c = $cname;
            $const->body = $ex->c;
        }
        $const->resolving = false;
        return $const;
    }

    // ---------------------------------------------------------------- one class

    private function unitFor(ClassInfo $class): string
    {
        $path = $this->program->relativePath($class->file);
        $this->units[$path] ??= ['file' => $class->file, 'protos' => [], 'statics' => [], 'funcs' => []];
        return $path;
    }

    private function compileClass(ClassInfo $class): void
    {
        $unit = $this->unitFor($class);

        foreach ($class->consts as $name => $const) {
            $this->constant($class, $name, ['line' => $const->line]);
        }
        foreach ($class->props as $prop) {
            if ($prop->type->isClass() && !$prop->type->nullable) {
                throw new CompileError("property \${$prop->name} holds an object: make it nullable (?{$prop->type->class}), objects are pointers", $class->file, $prop->line);
            }
        }
        foreach ($class->staticProps as $prop) {
            $this->compileStaticProp($class, $prop, $unit);
        }
        foreach ($class->methods as $method) {
            $stub = isset($class->attrs['Native']) || isset($method->attrs['Native']) || isset($method->attrs['Intrinsic']);
            if ($method->body === null || $stub) {
                continue; // the C side or the compiler provides it, the PHP body is only a stub
            }
            $proto = (new FunctionGen($this, $this->program, $class, $method));
            $this->units[$unit]['funcs'][] = $proto->compileMethod();
            $declaration = $this->signature($class, $method) . ';';
            if ($method->vis === 'public' || $method->export !== null) {
                $this->headerProtos[] = $declaration;
            } else {
                $this->units[$unit]['protos'][] = $declaration;
            }
        }
    }

    private function compileStaticProp(ClassInfo $class, PropInfo $prop, string $unit): void
    {
        $type = $prop->type;
        $name = $class->staticName($prop->name);
        $volatile = isset($prop->attrs['Volatile']) ? 'volatile ' : '';

        $storage = $type->kind === 'array' ? $type->elem : $type;
        $decl = $storage->isClass() && !$storage->nullable
            ? $this->structName($storage->class) . ' '
            : $this->cType($storage);
        $decl = $volatile . $decl . $name . ($type->kind === 'array' ? '[' . $type->length . ']' : '');

        $attrs = [];
        if (isset($prop->attrs['Used'])) {
            $attrs[] = 'used';
        }
        if (isset($prop->attrs['Section'])) {
            $attrs[] = 'section(' . $this->cString((string) $this->program->attrValue($prop->attrs['Section'][0], $class, $class->file)) . ')';
        }
        if (isset($prop->attrs['Align'])) {
            $attrs[] = 'aligned(' . $this->program->attrValue($prop->attrs['Align'][0], $class, $class->file) . ')';
        }
        $attributeText = $attrs === [] ? '' : ' __attribute__((' . implode(', ', $attrs) . '))';

        $init = '';
        if ($prop->default !== null && $prop->default['k'] !== 'null') {
            $init = ' = ' . (new FunctionGen($this, $this->program, $class))->initializer($prop->default, $type);
        }
        $internal = $prop->vis !== 'public';
        $this->units[$unit]['statics'][] = ($internal ? 'static ' : '') . $decl . $attributeText . $init . ';';
        if (!$internal) {
            $this->headerExterns[] = 'extern ' . $decl . ';';
        }
    }

    /** The allocating constructor for "new Class(...)". */
    private function compileNew(ClassInfo $class): void
    {
        if (isset($this->newDone[$class->fqcn])) {
            return;
        }
        $this->newDone[$class->fqcn] = true;
        $unit = $this->unitFor($class);
        $struct = $class->mangled;
        [$allocClass, $allocMethod] = explode('::', (string) $this->program->allocator) + [1 => ''];
        $alloc = $this->program->find(ltrim($allocClass, '\\'))->methods[$allocMethod]
            ?? throw new CompileError("allocator {$this->program->allocator} does not exist");

        $ctor = $class->methods['__construct'] ?? null;
        $params = [];
        $names = [];
        foreach ($ctor?->params ?? [] as $p) {
            if ($p->variadic) {
                throw new CompileError('a constructor cannot be variadic', $class->file, $class->line);
            }
            $params[] = $this->cType($p->type) . $this->ident($p->name);
            $names[] = $this->ident($p->name);
        }
        $signature = "$struct *{$struct}_new(" . ($params === [] ? 'void' : implode(', ', $params)) . ')';

        $body = ["    $struct *this = ($struct *)(uintptr_t){$alloc->cname}((uint64_t)sizeof($struct));", '    if (this == NULL) {', '        return NULL;', '    }'];
        $gen = new FunctionGen($this, $this->program, $class);
        foreach ($class->props as $prop) {
            if ($prop->type->kind === 'array') {
                $body[] = "    for (size_t i = 0; i < {$prop->type->length}; i++) {";
                $body[] = "        this->{$prop->name}[i] = 0;";
                $body[] = '    }';
                continue;
            }
            $value = $prop->default !== null ? $gen->initializer($prop->default, $prop->type) : match ($prop->type->kind) {
                'int' => '0', 'bool' => 'false', default => 'NULL',
            };
            $body[] = "    this->{$prop->name} = $value;";
        }
        if ($ctor !== null) {
            $body[] = "    {$ctor->cname}(" . implode(', ', ['this', ...$names]) . ');';
        }
        $body[] = '    return this;';
        $this->units[$unit]['funcs'][] = $signature . "\n{\n" . implode("\n", $body) . "\n}\n";
        $this->headerProtos[] = $signature . ';';
    }

    // ---------------------------------------------------------------- output files

    /** @param array{file:string,protos:list<string>,statics:list<string>,funcs:list<string>} $unit */
    private function unitText(array $unit): string
    {
        $parts = ['/* Generated by polyc from ' . basename($unit['file']) . '. Do not edit. */', '#include "polyc.h"', ''];
        foreach ([$unit['protos'], $unit['statics']] as $group) {
            if ($group !== []) {
                array_push($parts, ...$group);
                $parts[] = '';
            }
        }
        array_push($parts, ...$unit['funcs']);
        return implode("\n", $parts) . "\n";
    }

    private function header(): string
    {
        $classes = $this->program->all();
        ksort($classes);

        $out = ['/* Generated by polyc. Do not edit. */', '#pragma once', '', '#include <stdbool.h>', '#include <stddef.h>', '#include <stdint.h>'];
        $natives = [];
        foreach ($classes as $class) {
            foreach (['Native', 'Header'] as $attr) {
                if (($class->attrs[$attr] ?? []) !== []) {
                    $natives[] = '#include ' . $this->cString((string) $this->program->attrValue($class->attrs[$attr][0], $class, $class->file));
                }
            }
        }
        array_push($out, ...array_values(array_unique($natives)));
        $out[] = '';

        $structs = array_filter($classes, fn (ClassInfo $c): bool => $c->isStruct());
        foreach ($structs as $class) {
            $out[] = "typedef struct {$class->mangled} {$class->mangled};";
        }
        $out[] = '';
        foreach ($structs as $class) {
            array_push($out, ...$this->structText($class));
        }

        foreach ($classes as $class) {
            foreach ($class->consts as $const) {
                $out[] = $this->constantText($class, $const);
            }
        }
        $out[] = '';
        array_push($out, ...$this->headerExterns);
        $out[] = '';
        array_push($out, ...$this->headerProtos);
        return implode("\n", $out) . "\n";
    }

    private function constantText(ClassInfo $class, ConstInfo $const): string
    {
        $name = $class->mangled . '_' . $const->name;
        if ($const->type->kind !== 'array') {
            return "#define $name {$const->body}";
        }
        $elem = $const->type->elem;
        $init = (new FunctionGen($this, $this->program, $class))->initializer($const->expr, $const->type);
        $elemC = $elem->kind === 'string' ? 'const char *const ' : 'const ' . $this->cType($elem);
        $text = 'static ' . $elemC . $name . '[' . $const->type->length . '] = ' . $init . ';';
        if (isset($const->attrs['Count'])) {
            $want = $this->program->attrValue($const->attrs['Count'][0], $class, $class->file);
            $text .= "\n_Static_assert({$const->type->length} == $want, \"$name must have $want entries\");";
        }
        return $text;
    }

    /** @return list<string> */
    private function structText(ClassInfo $class): array
    {
        $attrs = [];
        if (isset($class->attrs['Packed'])) {
            $attrs[] = 'packed';
        }
        if (isset($class->attrs['Align'])) {
            $attrs[] = 'aligned(' . $this->program->attrValue($class->attrs['Align'][0], $class, $class->file) . ')';
        }
        $attributeText = $attrs === [] ? '' : ' __attribute__((' . implode(', ', $attrs) . '))';
        $lines = ["struct$attributeText {$class->mangled} {"];
        foreach ($class->props as $prop) {
            $isArray = $prop->type->kind === 'array';
            $lines[] = '    ' . $this->cType($isArray ? $prop->type->elem : $prop->type) . $prop->name
                . ($isArray ? '[' . $prop->type->length . ']' : '') . ';';
        }
        $lines[] = '};';
        if (isset($class->attrs['Size'])) {
            $size = $this->program->attrValue($class->attrs['Size'][0], $class, $class->file);
            $lines[] = "_Static_assert(sizeof({$class->mangled}) == $size, \"{$class->mangled} must be $size bytes\");";
        }
        $lines[] = '';
        return $lines;
    }
}
