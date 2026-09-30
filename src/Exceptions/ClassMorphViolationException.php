<?php

declare(strict_types=1);

namespace Loongs\Orm\Exceptions;

/** A polymorphic *_type value has no morph-map entry while Relation::enforceMorphMap() is on. */
final class ClassMorphViolationException extends \RuntimeException
{
    public static function forClass(string $class): self
    {
        return new self("No morph map alias for model [{$class}] (Relation::enforceMorphMap() is on; add it to the map).");
    }

    public static function forAlias(string $alias): self
    {
        return new self("No morph map entry for type [{$alias}] (Relation::enforceMorphMap() is on).");
    }
}
