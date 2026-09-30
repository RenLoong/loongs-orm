<?php

declare(strict_types=1);

namespace Loongs\Orm\Model;

/**
 * Soft deletes: delete() sets deleted_at; queries exclude trashed rows (SoftDeletingScope).
 * Builder: withTrashed() / onlyTrashed() / withoutTrashed() / restore() / forceDelete().
 */
trait SoftDeletes
{
    protected bool $forceDeleting = false;

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new SoftDeletingScope());
    }

    public function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? (string) constant(static::class . '::DELETED_AT') : 'deleted_at';
    }

    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }

    public function forceDelete(): bool
    {
        $this->forceDeleting = true;
        try {
            return $this->delete();
        } finally {
            $this->forceDeleting = false;
        }
    }

    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            parent::performDeleteOnModel();
            return;
        }
        $time = $this->freshTimestampString();
        $columns = [$this->getDeletedAtColumn() => $time];
        if ($this->usesTimestamps() && static::UPDATED_AT !== null) {
            $columns[static::UPDATED_AT] = $time;
        }
        $this->setKeysForSaveQuery($this->newQueryWithoutScopes()->getQuery())->update($columns);
        foreach ($columns as $k => $v) {
            $this->attributes[$k] = $v;
        }
        $this->syncOriginalAttributes(array_keys($columns));
    }

    public function restore(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $this->setAttribute($this->getDeletedAtColumn(), null);

        return $this->save();
    }

    public function trashed(): bool
    {
        return ($this->attributes[$this->getDeletedAtColumn()] ?? null) !== null;
    }

    /** Soft delete: model stays "existing" (row is still there). */
    protected function afterDelete(): void
    {
        $this->exists = !$this->forceDeleting;
    }
}
