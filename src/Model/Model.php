<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

use ArrayAccess;
use JsonSerializable;
use Loongs\Orm\Connection\Connection;
use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Exceptions\MassAssignmentException;
use Loongs\Orm\Model\Relations\Relation;
use Loongs\Orm\Orm;
use Loongs\Orm\Query\Builder as QueryBuilder;
use Loongs\Orm\Support\Inflector;
use Stringable;

/**
 * Active-record model (Eloquent-style).
 *
 * Connection resolution, evaluated every time a query is built — never cached statically:
 *   1. the connection this instance is bound to (on() / setConnection() / the connection it was
 *      loaded from or first saved to),
 *   2. the class's declared $connection (name, config array or DSN),
 *   3. the current Orm::tenant() scope of this coroutine,
 *   4. the default named connection.
 *
 * @method static Builder where(mixed ...$args)
 * @method static Builder with(string|array ...$relations)
 * @method static Model|null find(mixed $id, array $columns = ['*'])
 * @method static Model findOrFail(mixed $id, array $columns = ['*'])
 * @method static Model create(array $attributes = [])
 * @method static Model firstOrCreate(array $attributes, array $values = [])
 * @method static Model updateOrCreate(array $attributes, array $values = [])
 */
abstract class Model implements ArrayAccess, JsonSerializable, Stringable
{
    use Concerns\HasAttributes;
    use Concerns\HidesAttributes;
    use Concerns\HasRelationships;
    use Concerns\HasTimestamps;
    use Concerns\HasGlobalScopes;
    use Concerns\HasEvents;

    public const ?string CREATED_AT = 'created_at';

    public const ?string UPDATED_AT = 'updated_at';

    protected string $table = '';

    protected string $primaryKey = 'id';

    protected string $keyType = 'int';

    public bool $incrementing = true;

    /** Class default connection: name from config/database.php, config array or DSN. null → tenant scope / default. */
    protected string|array|null $connection = null;

    /** @var list<string> */
    protected array $fillable = [];

    /** @var list<string> */
    protected array $guarded = ['*'];

    /** @var list<string> relations always eager-loaded */
    protected array $with = [];

    protected int $perPage = 15;

    public bool $exists = false;

    public bool $wasRecentlyCreated = false;

    /** Connection this instance is bound to (per instance, never static). */
    private ?ConnectionConfig $boundConnection = null;

    /** @var array<class-string, true> class boot flags (metadata only) */
    private static array $booted = [];

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();
        $this->syncOriginal();
        $this->fill($attributes);
    }

    // ---------------------------------------------------------------- boot

    protected function bootIfNotBooted(): void
    {
        if (!isset(self::$booted[static::class])) {
            self::$booted[static::class] = true;
            static::boot();
            static::booted();
        }
    }

    protected static function boot(): void
    {
        foreach (Inflector::classUsesRecursive(static::class) as $trait) {
            $method = 'boot' . Inflector::classBasename($trait);
            if (method_exists(static::class, $method)) {
                static::$method();
            }
        }
    }

    /** Register global scopes and model event listeners here (static::creating(fn …), static::observe(…)). */
    protected static function booted(): void
    {
    }

    // ---------------------------------------------------------------- connection

    /** Query builder bound to $connection (name, config array, DSN or ConnectionConfig). */
    public static function on(string|array|ConnectionConfig|null $connection = null): Builder
    {
        return (new static())->setConnection($connection)->newQuery();
    }

    public function setConnection(string|array|ConnectionConfig|null $connection): static
    {
        $this->boundConnection = $connection === null ? null : Orm::resolver()->spec($connection);

        return $this;
    }

    /** Resolved right now (see class doc for the order); nothing is memoised except this instance's binding. */
    public function getConnectionConfig(): ConnectionConfig
    {
        return $this->boundConnection ?? Orm::resolver()->spec($this->connection);
    }

    public function getBoundConnection(): ?ConnectionConfig
    {
        return $this->boundConnection;
    }

    public function getConnectionName(): ?string
    {
        return $this->getConnectionConfig()->name;
    }

    public function hasDeclaredConnection(): bool
    {
        return $this->connection !== null;
    }

    public function getConnection(): Connection
    {
        return Orm::connection($this->getConnectionConfig());
    }

    // ---------------------------------------------------------------- queries

    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    public function newQuery(): Builder
    {
        $builder = $this->newQueryWithoutScopes();
        foreach (static::getGlobalScopes() as $id => $scope) {
            $builder->withGlobalScope($id, $scope);
        }

        return $builder;
    }

    public function newQueryWithoutScopes(): Builder
    {
        return (new Builder($this->getConnection()->table($this->getTable())))->setModel($this)->with(...$this->with);
    }

    /** @param list<string> $columns */
    public static function all(array $columns = ['*']): Collection
    {
        return static::query()->get($columns);
    }

    /** @param array<int, Model> $models */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /** @param array<string, mixed> $attributes */
    public function newInstance(array $attributes = [], bool $exists = false): static
    {
        $model = new static();
        $model->exists = $exists;
        $model->setTable($this->getTable());
        $model->boundConnection = $this->boundConnection;
        $model->fill($attributes);

        return $model;
    }

    /** @param array<string, mixed> $row */
    public function newFromBuilder(array $row, ConnectionConfig $connection): static
    {
        $model = $this->newInstance([], true);
        $model->setRawAttributes($row, true);
        $model->boundConnection = $connection;
        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /** Delete by primary key(s); loads each model so soft deletes apply. */
    public static function destroy(mixed ...$ids): int
    {
        $ids = array_merge(...array_map(static fn ($i): array => is_array($i) ? $i : [$i], $ids));
        if ($ids === []) {
            return 0;
        }
        $count = 0;
        $instance = new static();
        foreach (static::query()->whereIn($instance->getQualifiedKeyName(), $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------------- persistence

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        $query = $this->newQueryWithoutScopes()->getQuery();
        // Pin before any listener runs: listeners (and this save) always see the database this
        // instance resolved to, even if a listener switches Orm::tenant() or the save is cancelled.
        $this->boundConnection ??= $query->getConnection()->config;
        if (!$this->fireModelEvent('saving')) {
            return false;
        }
        $saved = $this->exists ? ($this->isDirty() ? $this->performUpdate($query) : true) : $this->performInsert($query);
        if ($saved) {
            $this->fireModelEvent('saved', false);
            $this->syncOriginal();
        }

        return $saved;
    }

    /** save() without firing any model event (this coroutine, for the duration of the call). @param array<string, mixed> $options */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn (): bool => $this->save($options));
    }

    protected function performInsert(QueryBuilder $query): bool
    {
        if (!$this->fireModelEvent('creating')) {
            return false;
        }
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }
        $attributes = $this->attributes;
        if ($this->incrementing && !array_key_exists($this->getKeyName(), $attributes)) {
            $id = $query->insertGetId($attributes);
            $this->attributes[$this->getKeyName()] = $id;
        } else {
            $query->insert($attributes);
        }
        $this->exists = true;
        $this->wasRecentlyCreated = true;
        $this->changes = [];
        $this->fireModelEvent('created', false);

        return true;
    }

    protected function performUpdate(QueryBuilder $query): bool
    {
        if (!$this->fireModelEvent('updating')) {
            return false;
        }
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }
        $dirty = $this->getDirty();
        if ($dirty !== []) {
            $this->setKeysForSaveQuery($query)->update($dirty);
            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    protected function setKeysForSaveQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->where($this->getKeyName(), '=', $this->original[$this->getKeyName()] ?? $this->getKey());
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes = []): bool
    {
        if (!$this->exists) {
            return false;
        }

        return $this->fill($attributes)->save();
    }

    /** @param array<string, mixed> $attributes */
    public function updateQuietly(array $attributes = []): bool
    {
        return static::withoutEvents(fn (): bool => $this->update($attributes));
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        if (!$this->fireModelEvent('deleting')) {
            return false;
        }
        $this->performDeleteOnModel();
        $this->afterDelete();
        $this->fireModelEvent('deleted', false);

        return true;
    }

    public function deleteQuietly(): bool
    {
        return static::withoutEvents(fn (): bool => $this->delete());
    }

    protected function performDeleteOnModel(): void
    {
        $this->setKeysForSaveQuery($this->newQueryWithoutScopes()->getQuery())->delete();
    }

    protected function afterDelete(): void
    {
        $this->exists = false;
    }

    /** @param array<string, mixed> $extra */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra);
    }

    /** @param array<string, mixed> $extra */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, -$amount, $extra);
    }

    /** @param array<string, mixed> $extra */
    protected function incrementOrDecrement(string $column, int|float $amount, array $extra): int
    {
        $query = $this->newQueryWithoutScopes()->getQuery();
        if (!$this->exists) {
            return $query->increment($column, $amount, $extra);
        }
        if (!$this->fireModelEvent('updating')) {
            return 0;
        }
        if ($this->usesTimestamps() && static::UPDATED_AT !== null && !isset($extra[static::UPDATED_AT])) {
            $extra[static::UPDATED_AT] = $this->freshTimestampString();
        }
        $n = $this->setKeysForSaveQuery($query)->increment($column, $amount, $extra);
        $this->attributes[$column] = ($this->attributes[$column] ?? 0) + $amount;
        foreach ($extra as $k => $v) {
            $this->setAttribute($k, $v);
        }
        $this->syncChanges();
        $this->fireModelEvent('updated', false);
        $this->syncOriginalAttributes(array_merge([$column], array_keys($extra)));

        return $n;
    }

    /** Reload attributes (and loaded relations) from the database this model belongs to. */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }
        $row = $this->newQueryWithoutScopes()->getQuery()->where($this->getKeyName(), '=', $this->getKey())->first();
        if ($row !== null) {
            $this->setRawAttributes($row, true);
        }
        $loaded = array_keys($this->relations);
        $this->relations = [];
        if ($loaded !== []) {
            $this->load(...$loaded);
        }

        return $this;
    }

    public function fresh(): ?static
    {
        if (!$this->exists) {
            return null;
        }

        return static::on($this->getConnectionConfig())->withoutGlobalScopes()->find($this->getKey());
    }

    /**
     * Unsaved copy (same connection / tenant) without primary key and timestamps; fires
     * "replicating" on the copy. @param list<string>|null $except
     */
    public function replicate(?array $except = null): static
    {
        $drop = array_filter([$this->getKeyName(), static::CREATED_AT, static::UPDATED_AT, ...($except ?? [])], static fn ($c): bool => $c !== null);
        $instance = $this->newInstance();
        $instance->setRawAttributes(array_diff_key($this->attributes, array_flip($drop)));
        $instance->setRelations($this->relations);
        $instance->fireModelEvent('replicating', false);

        return $instance;
    }

    public function is(?Model $other): bool
    {
        return $other !== null
            && $this->getKey() === $other->getKey()
            && $this->getTable() === $other->getTable()
            && $this->getConnectionConfig()->key === $other->getConnectionConfig()->key;
    }

    // ---------------------------------------------------------------- mass assignment

    /** @param array<string, mixed> $attributes */
    public function fill(array $attributes): static
    {
        $totallyGuarded = $this->totallyGuarded();
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException(sprintf('Add [%s] to the fillable property to allow mass assignment on [%s].', $key, static::class));
            }
        }

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->fillable, true)) {
            return true;
        }
        if ($this->isGuarded($key)) {
            return false;
        }

        return $this->fillable === [] && !str_contains($key, '.') && !str_starts_with($key, '_');
    }

    public function isGuarded(string $key): bool
    {
        return $this->guarded === ['*'] || in_array($key, $this->guarded, true);
    }

    public function totallyGuarded(): bool
    {
        return $this->fillable === [] && $this->guarded === ['*'];
    }

    /** @return list<string> */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    // ---------------------------------------------------------------- names

    public function getTable(): string
    {
        return $this->table !== '' ? $this->table : Inflector::plural(Inflector::snake(Inflector::classBasename($this)));
    }

    public function setTable(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function getQualifiedKeyName(): string
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    public function getKeyType(): string
    {
        return $this->keyType;
    }

    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    public function getForeignKey(): string
    {
        return Inflector::snake(Inflector::classBasename($this)) . '_' . $this->getKeyName();
    }

    /** Table prefix of the connection this model resolves to right now (bound / declared / tenant scope / default). */
    public function getTablePrefix(): string
    {
        return $this->getConnectionConfig()->prefix();
    }

    /** Physical table name (prefix + getTable()), unquoted — for raw SQL; the query builder adds the prefix itself. */
    public function getPrefixedTable(): string
    {
        return $this->getTablePrefix() . $this->getTable();
    }

    public function qualifyColumn(string $column): string
    {
        return str_contains($column, '.') ? $column : $this->getTable() . '.' . $column;
    }

    /** Value stored in *_type columns of polymorphic relations: the morph-map alias, else the class name. */
    public function getMorphClass(): string
    {
        return Relation::getMorphAlias(static::class);
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    // ---------------------------------------------------------------- serialisation

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->filterVisible(array_merge($this->attributesToArray(), $this->relationsToArray()));
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    // ---------------------------------------------------------------- magic

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->getAttribute((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset], $this->relations[$offset]);
    }

    /** @param list<mixed> $parameters */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->newQuery()->{$method}(...$parameters);
    }

    /** @param list<mixed> $parameters */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return (new static())->{$method}(...$parameters);
    }

    public function __clone()
    {
        $this->relations = [];
    }
}
