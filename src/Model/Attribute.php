<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

use Closure;

/**
 * Accessor / mutator definition (Laravel 9+ style):
 *
 *   protected function firstName(): Attribute
 *   {
 *       return Attribute::make(get: fn (?string $v) => ucfirst((string) $v), set: fn (string $v) => strtolower($v));
 *   }
 *
 * get: fn (mixed $value, array $attributes): mixed
 * set: fn (mixed $value, array $attributes): mixed|array<string, mixed> (array = several raw attributes)
 */
final readonly class Attribute
{
    public function __construct(
        public ?Closure $get = null,
        public ?Closure $set = null,
    ) {
    }

    public static function make(?callable $get = null, ?callable $set = null): self
    {
        return new self($get !== null ? $get(...) : null, $set !== null ? $set(...) : null);
    }

    public static function get(callable $get): self
    {
        return new self($get(...));
    }

    public static function set(callable $set): self
    {
        return new self(null, $set(...));
    }
}
