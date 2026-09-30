<?php

declare(strict_types=1);

namespace Loongs\Orm\Casts;

use Loongs\Orm\Model\Model;

/** Custom cast: 'casts' => ['address' => AddressCast::class]. */
interface CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * @param array<string, mixed> $attributes
     * @return mixed|array<string, mixed> scalar for $key, or several raw attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
