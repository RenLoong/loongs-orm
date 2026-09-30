<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Relations;

use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Model;

/**
 * Polymorphic many-to-many through a pivot with a {name}_type column.
 *   morphToMany:   $post->tags   (taggables.taggable_id = post.id, taggable_type = 'post')
 *   morphedByMany: $tag->posts   (taggables.tag_id = tag.id,      taggable_type = 'post')
 * attach / detach / sync / updateExistingPivot / withPivot / withTimestamps as belongsToMany;
 * every pivot read and write carries the type condition, on the parent's connection.
 */
final class MorphToMany extends BelongsToMany
{
    public function __construct(
        Builder $query,
        Model $parent,
        string $morphType,
        string $morphClass,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        string $relationName,
        private readonly bool $inverse = false,
    ) {
        $this->morphType = $morphType;
        $this->morphClass = $morphClass;
        parent::__construct($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    public function getMorphType(): string
    {
        return (string) $this->morphType;
    }

    public function getMorphClass(): string
    {
        return (string) $this->morphClass;
    }

    /** true for morphedByMany (the side without the type column). */
    public function isInverse(): bool
    {
        return $this->inverse;
    }
}
