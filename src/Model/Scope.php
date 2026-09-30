<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

/** Global scope: applied to every query of the model unless removed with withoutGlobalScope(). */
interface Scope
{
    public function apply(Builder $builder, Model $model): void;
}
