<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * For models mapped onto POS tables: the loyalty app reads them, never
 * writes them. Blocks every write that goes through the model (create,
 * save, update, delete, increment). Query-builder writes such as
 * PosOrder::query()->update() bypass models and must not be used.
 */
trait ReadOnlyModel
{
    public function save(array $options = []): bool
    {
        throw $this->readOnlyViolation('save');
    }

    public function delete(): ?bool
    {
        throw $this->readOnlyViolation('delete');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw $this->readOnlyViolation($method);
    }

    private function readOnlyViolation(string $operation): LogicException
    {
        return new LogicException(sprintf(
            '%s is read-only: it maps the POS table "%s", which the loyalty app must not %s.',
            static::class,
            $this->getTable(),
            $operation,
        ));
    }
}
