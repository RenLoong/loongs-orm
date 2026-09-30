<?php

declare(strict_types=1);

namespace Loongs\Orm\Query;

/** Raw SQL fragment, inserted verbatim (never quoted, never bound). Only for trusted SQL. */
final readonly class Expression implements \Stringable
{
    public function __construct(public string|int|float $value)
    {
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
