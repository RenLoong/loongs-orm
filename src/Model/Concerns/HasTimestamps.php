<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

use DateTimeImmutable;

trait HasTimestamps
{
    public bool $timestamps = true;

    public function usesTimestamps(): bool
    {
        return $this->timestamps;
    }

    public function freshTimestamp(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function freshTimestampString(): string
    {
        return $this->freshTimestamp()->format($this->getDateFormat());
    }

    protected function updateTimestamps(): void
    {
        $time = $this->freshTimestampString();
        if (static::UPDATED_AT !== null && !$this->isDirty(static::UPDATED_AT)) {
            $this->attributes[static::UPDATED_AT] = $time;
        }
        if (!$this->exists && static::CREATED_AT !== null && !$this->isDirty(static::CREATED_AT)) {
            $this->attributes[static::CREATED_AT] = $time;
        }
    }

    public function touch(): bool
    {
        if (!$this->usesTimestamps()) {
            return false;
        }
        $this->updateTimestamps();

        return $this->save();
    }
}
