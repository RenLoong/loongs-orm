<?php

declare(strict_types=1);

namespace Loongs\Orm\Model\Concerns;

trait HidesAttributes
{
    /** @var list<string> */
    protected array $hidden = [];

    /** @var list<string> */
    protected array $visible = [];

    /** @return list<string> */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /** @param list<string> $hidden */
    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    /** @return list<string> */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /** @param list<string> $visible */
    public function setVisible(array $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function makeHidden(string|array ...$attributes): static
    {
        $attrs = array_merge(...array_map(static fn ($a): array => (array) $a, $attributes));
        $this->hidden = array_values(array_unique(array_merge($this->hidden, $attrs)));
        $this->visible = array_values(array_diff($this->visible, $attrs));

        return $this;
    }

    public function makeVisible(string|array ...$attributes): static
    {
        $attrs = array_merge(...array_map(static fn ($a): array => (array) $a, $attributes));
        $this->hidden = array_values(array_diff($this->hidden, $attrs));
        if ($this->visible !== []) {
            $this->visible = array_values(array_unique(array_merge($this->visible, $attrs)));
        }

        return $this;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    protected function filterVisible(array $values): array
    {
        if ($this->visible !== []) {
            $values = array_intersect_key($values, array_flip($this->visible));
        }
        if ($this->hidden !== []) {
            $values = array_diff_key($values, array_flip($this->hidden));
        }

        return $values;
    }
}
