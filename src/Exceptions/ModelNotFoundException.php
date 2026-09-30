<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

use RuntimeException;

final class ModelNotFoundException extends RuntimeException
{
    /** @param list<int|string> $ids */
    public function __construct(public readonly string $model, public readonly array $ids = [])
    {
        parent::__construct(sprintf('No query results for model [%s]%s', $model, $ids === [] ? '.' : ' ' . implode(', ', $ids)));
    }
}
