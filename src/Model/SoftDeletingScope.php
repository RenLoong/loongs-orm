<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

final class SoftDeletingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var Model&SoftDeletes $model */
        $builder->whereNull($model->getQualifiedDeletedAtColumn());
    }
}
