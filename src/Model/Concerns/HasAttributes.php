<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Loongs\Orm\Casts\CastsAttributes;
use Loongs\Orm\Model\Attribute;
use Loongs\Orm\Support\Collection;
use Loongs\Orm\Support\Inflector;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

trait HasAttributes
{
    /** @var array<string, mixed> raw (storage) values */
    protected array $attributes = [];

    /** @var array<string, mixed> raw values as last loaded / saved */
    protected array $original = [];

    /** @var array<string, mixed> attributes changed by the last save() */
    protected array $changes = [];

    /** @var array<string, string> */
    protected array $casts = [];

    /** @var list<string> accessor-only attributes added to toArray() */
    protected array $appends = [];

    protected string $dateFormat = 'Y-m-d H:i:s';

    /** @var array<class-string, array<string, Attribute|false>> accessor lookup, class metadata only */
    private static array $attributeAccessorCache = [];

    /** Override for method-style casts (merged over $casts). @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function getCasts(): array
    {
        $casts = array_merge($this->casts, $this->casts());
        if ($this->getIncrementing() && !isset($casts[$this->getKeyName()])) {
            $casts[$this->getKeyName()] = 'int';
        }
        if ($this->usesTimestamps()) {
            foreach ([static::CREATED_AT, static::UPDATED_AT] as $c) {
                if ($c !== null && !isset($casts[$c])) {
                    $casts[$c] = 'datetime';
                }
            }
        }
        if (method_exists($this, 'getDeletedAtColumn') && !isset($casts[$this->getDeletedAtColumn()])) {
            $casts[$this->getDeletedAtColumn()] = 'datetime';
        }

        return $casts;
    }

    public function hasCast(string $key): bool
    {
        return array_key_exists($key, $this->getCasts());
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    // ---------------------------------------------------------------- read

    public function getAttribute(string $key): mixed
    {
        if ($key === '') {
            return null;
        }
        if (array_key_exists($key, $this->attributes) || $this->hasGetMutator($key) || $this->attributeAccessor($key)?->get !== null) {
            return $this->getAttributeValue($key);
        }
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }
        if ($this->isRelation($key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    public function getAttributeValue(string $key): mixed
    {
        return $this->transformModelValue($key, $this->attributes[$key] ?? null, $this->attributes);
    }

    /**
     * Apply get-mutator / Attribute accessor / cast to a raw value.
     *
     * @param array<string, mixed> $context attribute set handed to Attribute getters
     */
    protected function transformModelValue(string $key, mixed $value, array $context): mixed
    {
        if ($this->hasGetMutator($key)) {
            return $this->{'get' . Inflector::studly($key) . 'Attribute'}($value);
        }
        $accessor = $this->attributeAccessor($key);
        if ($accessor?->get !== null) {
            return ($accessor->get)($this->hasCast($key) ? $this->castAttribute($key, $value) : $value, $context);
        }
        if ($this->hasCast($key)) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . Inflector::studly($key) . 'Attribute');
    }

    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . Inflector::studly($key) . 'Attribute');
    }

    /** Method named camel($key) returning Attribute (Laravel 9+ accessors). */
    protected function attributeAccessor(string $key): ?Attribute
    {
        $cache = &self::$attributeAccessorCache[static::class];
        if (!isset($cache[$key])) {
            $cache[$key] = false;
            $method = Inflector::camel($key);
            if ($method !== '' && method_exists($this, $method)) {
                $rm = new ReflectionMethod($this, $method);
                $type = $rm->getReturnType();
                if ($type instanceof ReflectionNamedType && $type->getName() === Attribute::class && $rm->getNumberOfRequiredParameters() === 0) {
                    $cache[$key] = true;
                }
            }
        }
        if ($cache[$key] === false) {
            return null;
        }

        return (fn () => $this->{Inflector::camel($key)}())->call($this);
    }

    public function castAttribute(string $key, mixed $value): mixed
    {
        $cast = $this->getCasts()[$key] ?? null;
        if ($cast === null) {
            return $value;
        }
        [$type, $arg] = array_pad(explode(':', $cast, 2), 2, null);
        if (class_exists($type) && is_subclass_of($type, CastsAttributes::class)) {
            return (new $type())->get($this, $key, $value, $this->attributes);
        }
        if ($value === null) {
            return null;
        }
        if (enum_exists($type)) {
            if ($value instanceof $type) {
                return $value;
            }
            return is_subclass_of($type, BackedEnum::class) ? $type::from($value) : constant($type . '::' . $value);
        }

        return match (strtolower($type)) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'decimal' => number_format((float) $value, (int) ($arg ?? 2), '.', ''),
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            'object' => is_object($value) ? $value : json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR),
            'collection' => new Collection(is_array($value) ? $value : (array) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR)),
            'datetime', 'immutable_datetime', 'custom_datetime' => $this->asDateTime($value),
            'date', 'immutable_date' => $this->asDateTime($value)->setTime(0, 0),
            'timestamp' => $this->asDateTime($value)->getTimestamp(),
            default => $value,
        };
    }

    protected function asDateTime(mixed $value): DateTimeImmutable
    {
        return match (true) {
            $value instanceof DateTimeImmutable => $value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            is_int($value) || (is_string($value) && ctype_digit($value)) => (new DateTimeImmutable())->setTimestamp((int) $value),
            default => new DateTimeImmutable((string) $value),
        };
    }

    // ---------------------------------------------------------------- write

    public function setAttribute(string $key, mixed $value): static
    {
        if ($this->hasSetMutator($key)) {
            $this->{'set' . Inflector::studly($key) . 'Attribute'}($value);
            return $this;
        }
        $accessor = $this->attributeAccessor($key);
        if ($accessor?->set !== null) {
            $result = ($accessor->set)($value, $this->attributes);
            if (is_array($result) && !array_is_list($result)) {
                foreach ($result as $k => $v) {
                    $this->attributes[$k] = $this->castForStorage((string) $k, $v);
                }
            } else {
                $this->attributes[$key] = $this->castForStorage($key, $result);
            }
            return $this;
        }
        $this->attributes[$key] = $this->castForStorage($key, $value);

        return $this;
    }

    /** Convert a PHP value into its storage representation according to the cast. */
    protected function castForStorage(string $key, mixed $value): mixed
    {
        $cast = $this->getCasts()[$key] ?? null;
        if ($cast !== null) {
            [$type, $arg] = array_pad(explode(':', $cast, 2), 2, null);
            if (class_exists($type) && is_subclass_of($type, CastsAttributes::class)) {
                $r = (new $type())->set($this, $key, $value, $this->attributes);
                return is_array($r) && array_key_exists($key, $r) ? $r[$key] : $r;
            }
            if ($value === null) {
                return null;
            }
            if (enum_exists($type)) {
                $enum = $value instanceof UnitEnum ? $value : (is_subclass_of($type, BackedEnum::class) ? $type::from($value) : constant($type . '::' . $value));
                return $enum instanceof BackedEnum ? $enum->value : $enum->name;
            }
            switch (strtolower($type)) {
                case 'array':
                case 'json':
                case 'object':
                case 'collection':
                    if (is_string($value) && json_validate($value)) {
                        return $value;
                    }
                    $value = $value instanceof Collection ? $value->toArray() : $value;
                    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                case 'datetime':
                case 'immutable_datetime':
                case 'custom_datetime':
                case 'date':
                case 'immutable_date':
                    $dt = $this->asDateTime($value);
                    if (in_array(strtolower($type), ['date', 'immutable_date'], true)) {
                        return $dt->format('Y-m-d');
                    }
                    return $dt->format($this->getDateFormat());
                case 'timestamp':
                    return $this->asDateTime($value)->getTimestamp();
                case 'bool':
                case 'boolean':
                    return (int) (bool) $value;
                case 'int':
                case 'integer':
                    return is_numeric($value) ? (int) $value : $value;
                case 'float':
                case 'real':
                case 'double':
                    return (float) $value;
                case 'decimal':
                    return number_format((float) $value, (int) ($arg ?? 2), '.', '');
                case 'string':
                    return (string) $value;
            }
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format($this->getDateFormat()),
            default => $value,
        };
    }

    // ---------------------------------------------------------------- raw / original / dirty

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setRawAttributes(array $attributes, bool $sync = false): static
    {
        $this->attributes = $attributes;
        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    /** Original value(s) with casts and accessors applied (like getAttribute). */
    public function getOriginal(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            $out = [];
            foreach ($this->original as $k => $v) {
                $out[$k] = $this->transformModelValue($k, $v, $this->original);
            }
            return $out;
        }
        if (!array_key_exists($key, $this->original)) {
            return $default;
        }

        return $this->transformModelValue($key, $this->original[$key], $this->original);
    }

    public function getRawOriginal(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->original : (array_key_exists($key, $this->original) ? $this->original[$key] : $default);
    }

    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    /** @param list<string> $keys */
    public function syncOriginalAttributes(array $keys): static
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $this->attributes)) {
                $this->original[$k] = $this->attributes[$k];
            }
        }

        return $this;
    }

    public function syncChanges(): static
    {
        $this->changes = $this->getDirty();

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $k => $v) {
            if (!$this->originalIsEquivalent($k)) {
                $dirty[$k] = $v;
            }
        }

        return $dirty;
    }

    public function isDirty(string|array ...$attributes): bool
    {
        $dirty = $this->getDirty();
        $attrs = array_merge(...array_map(static fn ($a): array => (array) $a, $attributes ?: [[]]));
        if ($attrs === []) {
            return $dirty !== [];
        }
        foreach ($attrs as $a) {
            if (array_key_exists($a, $dirty)) {
                return true;
            }
        }

        return false;
    }

    public function isClean(string|array ...$attributes): bool
    {
        return !$this->isDirty(...$attributes);
    }

    /** @return array<string, mixed> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function wasChanged(string|array ...$attributes): bool
    {
        $attrs = array_merge(...array_map(static fn ($a): array => (array) $a, $attributes ?: [[]]));
        if ($attrs === []) {
            return $this->changes !== [];
        }
        foreach ($attrs as $a) {
            if (array_key_exists($a, $this->changes)) {
                return true;
            }
        }

        return false;
    }

    public function originalIsEquivalent(string $key): bool
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }
        $a = $this->attributes[$key] ?? null;
        $o = $this->original[$key];
        if ($a === $o) {
            return true;
        }
        if ($a === null || $o === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($o)) {
            return (string) $a === (string) $o || (float) $a === (float) $o;
        }
        $cast = strtolower(explode(':', $this->getCasts()[$key] ?? '', 2)[0]);
        if (in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
            return json_decode((string) $a, true) == json_decode((string) $o, true);
        }
        if (in_array($cast, ['datetime', 'immutable_datetime', 'date', 'immutable_date', 'timestamp'], true)) {
            try {
                return $this->asDateTime($a)->getTimestamp() === $this->asDateTime($o)->getTimestamp();
            } catch (\Throwable) {
                return false;
            }
        }

        return (string) $a === (string) $o;
    }

    // ---------------------------------------------------------------- serialisation

    /** @return array<string, mixed> */
    public function attributesToArray(): array
    {
        $out = [];
        foreach (array_keys($this->attributes) as $k) {
            $out[$k] = $this->serializeValue($k, $this->getAttributeValue($k));
        }
        foreach ($this->appends as $k) {
            $out[$k] = $this->serializeValue($k, $this->getAttributeValue($k));
        }

        return $out;
    }

    protected function serializeValue(string $key, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $cast = $this->getCasts()[$key] ?? '';
            $fmt = str_contains($cast, ':') ? explode(':', $cast, 2)[1] : (str_starts_with($cast, 'date') && !str_starts_with($cast, 'datetime') ? 'Y-m-d' : $this->getDateFormat());
            return $value->format($fmt);
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Collection => $value->toArray(),
            default => $value,
        };
    }

    /** @param list<string> $attributes @return array<string, mixed> */
    public function only(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $a) {
            $out[$a] = $this->getAttribute($a);
        }

        return $out;
    }
}
