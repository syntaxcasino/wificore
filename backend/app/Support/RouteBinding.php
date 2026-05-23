<?php

namespace App\Support;

class RouteBinding
{
    public static function isSentinel(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['', 'undefined', 'null'], true);
    }
}
