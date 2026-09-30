<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

use Loongs\Orm\Connection\ConnectionConfig;

/** Row of a belongsToMany pivot table, exposed as $model->pivot. */
class Pivot extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;

    public bool $incrementing = false;

    /** @param array<string, mixed> $attributes */
    public static function fromRaw(string $table, array $attributes, ConnectionConfig $connection): static
    {
        $p = new static();
        $p->setTable($table);
        $p->setRawAttributes($attributes, true);
        $p->exists = true;
        $p->setConnection($connection);

        return $p;
    }
}
