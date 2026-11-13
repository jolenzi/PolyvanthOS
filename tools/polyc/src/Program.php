<?php
// SPDX-FileCopyrightText: 2026 PolyvanthOS contributors
// SPDX-License-Identifier: GPL-2.0-only

declare(strict_types=1);

namespace Polyc;

/**
 * All the classes of the program being compiled.
 *
 * Classes are found the way PHP's PSR-4 autoloading finds them, with one difference: the
 * namespace folders are lower case on disk. With the prefix Polyvanthos mapped to src/, the class
 * Polyvanthos\Arch\X86_64\GDT lives in src/arch/x86_64/GDT.php.
 */
final class Program
{
    /** @var array<string,ClassInfo> by fully qualified name */
    private array $classes = [];

    /** @param array<string,string> $psr4 namespace prefix => directory */
    public function __construct(private readonly array $psr4, public readonly ?string $allocator = null)
    {
    }

    /** Parse a file and register its classes. @return list<ClassInfo> */
    public function loadFile(string $path): array
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new CompileError("cannot read the file", $path);
        }
        $parsed = (new Parser($source, $path))->parseFile();
        $loaded = [];
        foreach ($parsed['classes'] as $decl) {
            $loaded[] = $this->register($decl, $path);
        }
        return $loaded;
    }

    /** Look a class up, loading its file if needed. */
    public function find(string $fqcn, string $file = '', int $line = 0): ClassInfo
    {
        if (isset($this->classes[$fqcn])) {
            return $this->classes[$fqcn];
        }
        $path = $this->pathFor($fqcn);
        if ($path === null || !is_file($path)) {
            throw new CompileError("unknown class '$fqcn'", $file, $line);
        }
        $this->loadFile($path);
        if (!isset($this->classes[$fqcn])) {
            throw new CompileError("file $path does not define class '$fqcn'", $file, $line);
        }
        return $this->classes[$fqcn];
    }

    /** @return array<string,ClassInfo> */
    public function all(): array
    {
        return $this->classes;
    }

    private function pathFor(string $fqcn): ?string
    {
        $best = null;
        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix . '\\') && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }
        if ($best === null) {
            return null;
        }
        $segments = explode('\\', substr($fqcn, strlen($best) + 1));
        $class = array_pop($segments);
        $folders = array_map('strtolower', $segments);
        return rtrim($this->psr4[$best], '/') . '/' . implode('/', [...$folders, $class . '.php']);
    }

    /** The path of a source file below its namespace folder, for naming the generated file. */
    public function relativePath(string $file): string
    {
        $real = realpath($file) ?: $file;
        foreach ($this->psr4 as $dir) {
            $root = (realpath($dir) ?: $dir) . '/';
            if (str_starts_with($real, $root)) {
                return substr($real, strlen($root));
            }
        }
        return basename($file);
    }

    private function mangle(string $fqcn): string
    {
        foreach ($this->psr4 as $prefix => $dir) {
            if (str_starts_with($fqcn, $prefix . '\\')) {
                $fqcn = substr($fqcn, strlen($prefix) + 1);
                break;
            }
        }
        return str_replace('\\', '_', $fqcn);
    }

    // ---------------------------------------------------------------- registering classes

    /** @param array<string,mixed> $decl */
    private function register(array $decl, string $file): ClassInfo
    {
        $fqcn = $decl['name'];
        if (isset($this->classes[$fqcn])) {
            throw new CompileError("class '$fqcn' is declared twice", $file, $decl['line']);
        }
        $info = new ClassInfo($fqcn, $decl['short'], $this->mangle($fqcn), $file, $decl['line'], $this->attrMap($decl['attrs']));
        $this->classes[$fqcn] = $info;

        foreach ($decl['consts'] as $c) {
            if (isset($info->consts[$c['name']])) {
                throw new CompileError("constant {$c['name']} is declared twice", $file, $c['line']);
            }
            $info->consts[$c['name']] = new ConstInfo($c['name'], $c['expr'], $c['vis'], $this->attrMap($c['attrs']), $c['line']);
        }

        foreach ($decl['props'] as $p) {
            $attrs = $this->attrMap($p['attrs']);
            $prop = new PropInfo(
                $p['name'], $this->propType($p, $attrs, $info, $file), $p['static'], $p['vis'], $p['default'], $attrs, $p['line'],
            );
            $isDuplicate = isset($info->props[$p['name']]) || isset($info->staticProps[$p['name']]);
            if ($isDuplicate) {
                throw new CompileError("property \${$p['name']} is declared twice", $file, $p['line']);
            }
            if ($p['static']) {
                $info->staticProps[$p['name']] = $prop;
            } else {
                $info->props[$p['name']] = $prop;
            }
        }

        foreach ($decl['methods'] as $m) {
            $this->registerMethod($info, $m, $file);
        }
        return $info;
    }

    /**
     * @param list<array<string,mixed>> $attrs
     * @return array<string,list<array<string,mixed>>>
     */
    private function attrMap(array $attrs): array
    {
        $map = [];
        foreach ($attrs as $a) {
            $map[$a['name']] = $a['args'];
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,list<array<string,mixed>>> $attrs
     */
    private function propType(array $p, array $attrs, ClassInfo $info, string $file): Type
    {
        if ($p['type'] === null) {
            throw new CompileError("property \${$p['name']} needs a type", $file, $p['line']);
        }
        if ($p['type']['name'] === 'array') {
            if (!isset($attrs['FixedArray']) || count($attrs['FixedArray']) !== 2) {
                throw new CompileError("an array property needs #[FixedArray('type', length)]", $file, $p['line']);
            }
            $elem = $this->attrValue($attrs['FixedArray'][0], $info, $file);
            $length = $this->attrValue($attrs['FixedArray'][1], $info, $file);
            if (!is_string($elem) || !is_int($length) || $length <= 0) {
                throw new CompileError("#[FixedArray] needs a type name and a positive constant length", $file, $p['line']);
            }
            return Type::array($this->typeFrom(['name' => $elem, 'nullable' => false], $info, $file, $p['line']), $length);
        }
        return $this->typeFrom($p['type'], $info, $file, $p['line']);
    }

    /** @param array<string,mixed> $m */
    private function registerMethod(ClassInfo $info, array $m, string $file): void
    {
        $name = $m['name'];
        if (isset($info->methods[$name])) {
            throw new CompileError("method $name is declared twice", $file, $m['line']);
        }
        if ($m['ret'] === null && $name === '__construct') {
            $m['ret'] = ['name' => 'void', 'nullable' => false];
        }
        if ($m['ret'] === null) {
            throw new CompileError("method $name needs a return type", $file, $m['line']);
        }
        $attrs = $this->attrMap($m['attrs']);
        $params = [];
        foreach ($m['params'] as $p) {
            if ($p['type'] === null) {
                throw new CompileError("parameter \${$p['name']} needs a type", $file, $p['line']);
            }
            $params[] = new ParamInfo($p['name'], $this->typeFrom($p['type'], $info, $file, $p['line'], $p['variadic']), $p['variadic'], $p['default']);
        }
        $export = null;
        if (isset($attrs['Export'])) {
            $export = $this->attrValue($attrs['Export'][0] ?? ['k' => 'null', 'line' => 0], $info, $file);
            if (!is_string($export)) {
                throw new CompileError("#[Export] needs the C name as a string", $file, $m['line']);
            }
        }
        $isNative = isset($info->attrs['Native']) || isset($attrs['Native']) || isset($attrs['Intrinsic']);
        if ($m['body'] === null && !$isNative) {
            throw new CompileError("method $name has no body", $file, $m['line']);
        }
        if ($isNative && !$m['static']) {
            throw new CompileError("native and intrinsic methods must be static", $file, $m['line']);
        }
        $info->methods[$name] = new MethodInfo(
            $name, $m['static'], $m['vis'], $params, $this->typeFrom($m['ret'], $info, $file, $m['line']),
            $m['body'], $attrs, $m['line'], $export ?? ($info->mangled . '_' . $name), $export,
        );
    }

    /** @param array{name:string,nullable:bool} $node */
    public function typeFrom(array $node, ClassInfo $ctx, string $file, int $line, bool $variadic = false): Type
    {
        $name = $node['name'];
        $type = Type::named($name);
        if ($name === 'mixed' && !$variadic) {
            throw new CompileError("'mixed' can only be used for variadic parameters", $file, $line);
        }
        if ($name === 'array') {
            throw new CompileError("'array' can only be used with #[FixedArray]", $file, $line);
        }
        if ($type === null) {
            $type = Type::ofClass($name === 'self' ? $ctx->fqcn : $name);
        }
        if ($node['nullable']) {
            if (!in_array($type->kind, ['class', 'string'], true)) {
                throw new CompileError("only classes and strings can be nullable, not {$type->name()}", $file, $line);
            }
            $type = $type->withNullable();
        }
        return $type;
    }

    // ---------------------------------------------------------------- attribute values

    /** The value of an attribute argument: a string, an int, or a class name for Foo::class. */
    public function attrValue(array $node, ClassInfo $ctx, string $file): string|int|null
    {
        if ($node['k'] === 'str') {
            return $node['v'];
        }
        if ($node['k'] === 'cconst' && $node['name'] === 'class') {
            return $node['cls'] === 'self' ? $ctx->fqcn : $node['cls'];
        }
        $value = $this->foldInt($node, $ctx);
        if ($value === null) {
            throw new CompileError('attribute arguments must be constants', $file, $node['line']);
        }
        return $value;
    }

    /** Evaluate a whole-number constant expression, or return null if it is not one. */
    /**
     * The text of a constant string expression, or null if it is not one. That is a string literal, a
     * whole-number constant (written in decimal), a class constant holding either, or any of those
     * joined with '.'. Everything is known at build time, so the result is one string in the C code.
     *
     * @param array<string,mixed> $node
     */
    public function foldString(array $node, ClassInfo $ctx): ?string
    {
        switch ($node['k']) {
            case 'str':
                return $node['v'];
            case 'int':
                return $node['big'] ? sprintf('%u', $node['v']) : (string) $node['v'];
            case 'bin':
                if ($node['op'] === '.') {
                    $left = $this->foldString($node['l'], $ctx);
                    $right = $left === null ? null : $this->foldString($node['r'], $ctx);
                    return $right === null ? null : $left . $right;
                }
                break;
            case 'cconst':
                $class = $node['cls'] === 'self' ? $ctx : $this->find($node['cls'], $ctx->file, $node['line']);
                $const = $class->consts[$node['name']] ?? null;
                return $const === null ? null : $this->foldString($const->expr, $class);
        }
        $value = $this->foldInt($node, $ctx);
        return $value === null ? null : (string) $value;
    }

    public function foldInt(array $node, ClassInfo $ctx): ?int
    {
        switch ($node['k']) {
            case 'int':
                return $node['v'];
            case 'un':
                $v = $this->foldInt($node['e'], $ctx);
                $r = $v === null ? null : match ($node['op']) {
                    '-' => -$v, '~' => ~$v, '+' => $v, default => null,
                };
                return is_int($r) ? $r : null;
            case 'bin':
                $l = $this->foldInt($node['l'], $ctx);
                $r = $this->foldInt($node['r'], $ctx);
                if ($l === null || $r === null) {
                    return null;
                }
                if ($node['op'] === '<<' || $node['op'] === '>>') {
                    if ($r < 0 || $r > 63) {
                        return null;
                    }
                }
                $v = match ($node['op']) {
                    '+' => $l + $r, '-' => $l - $r, '*' => $l * $r, '<<' => $l << $r, '>>' => $l >> $r,
                    '|' => $l | $r, '&' => $l & $r, '^' => $l ^ $r, default => null,
                };
                return is_int($v) ? $v : null;
            case 'cconst':
                $class = $node['cls'] === 'self' ? $ctx : $this->find($node['cls'], $ctx->file, $node['line']);
                $const = $class->consts[$node['name']] ?? null;
                return $const === null ? null : $this->foldInt($const->expr, $class);
        }
        return null;
    }
}
