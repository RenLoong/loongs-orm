<?php

declare(strict_types=1);

namespace Loongs\Orm\Support;

use Loongs\Helper\Str;

/** Minimal English inflection for default table / key names. */
final class Inflector
{
    private const array IRREGULAR = [
        'person' => 'people', 'man' => 'men', 'woman' => 'women', 'child' => 'children',
        'tooth' => 'teeth', 'foot' => 'feet', 'mouse' => 'mice', 'goose' => 'geese',
    ];

    private const array UNCOUNTABLE = ['data', 'equipment', 'information', 'money', 'news', 'series', 'species', 'metadata', 'feedback'];

    public static function plural(string $word): string
    {
        $lower = strtolower($word);
        $pos = strrpos($lower, '_');
        $last = $pos === false ? $lower : substr($lower, $pos + 1);
        $head = $pos === false ? '' : substr($word, 0, $pos + 1);
        if (in_array($last, self::UNCOUNTABLE, true)) {
            return $word;
        }
        if (isset(self::IRREGULAR[$last])) {
            return $head . self::IRREGULAR[$last];
        }

        return $head . match (true) {
            (bool) preg_match('/[^aeiou]y$/', $last) => substr($last, 0, -1) . 'ies',
            (bool) preg_match('/(s|x|z|ch|sh)$/', $last) => $last . 'es',
            default => $last . 's',
        };
    }

    public static function snake(string $value): string
    {
        return Str::snake($value);
    }

    public static function studly(string $value): string
    {
        return Str::studly($value);
    }

    public static function camel(string $value): string
    {
        return Str::camel($value);
    }

    public static function classBasename(string|object $class): string
    {
        $class = is_object($class) ? $class::class : $class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** @return array<string, string> all traits of $class, its parents and nested traits */
    public static function classUsesRecursive(string|object $class): array
    {
        $class = is_object($class) ? $class::class : $class;
        $out = [];
        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $c) {
            $stack = class_uses($c) ?: [];
            while ($stack !== []) {
                $t = array_shift($stack);
                if (!isset($out[$t])) {
                    $out[$t] = $t;
                    foreach (class_uses($t) ?: [] as $nested) {
                        $stack[] = $nested;
                    }
                }
            }
        }

        return $out;
    }
}
