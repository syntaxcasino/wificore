<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use DateTimeInterface;

class PostgresConnection extends BasePostgresConnection
{
    /**
     * Normalize booleans for PostgreSQL when PDO emulates prepares.
     *
     * PgBouncer transaction pooling in this deployment requires emulated
     * prepares, but PDO then serializes booleans as 1/0. PostgreSQL boolean
     * columns reject comparisons like "is_active = 1", so convert bool
     * bindings to PostgreSQL boolean literals before execution.
     */
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }
}
