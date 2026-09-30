<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

use RuntimeException;

final class RelationNotFoundException extends RuntimeException
{
    public static function make(object $model, string $relation): self
    {
        return new self(sprintf('Call to undefined relationship [%s] on model [%s].', $relation, $model::class));
    }
}
