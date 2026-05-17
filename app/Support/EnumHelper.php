<?php

declare(strict_types=1);

namespace App\Support;

final class EnumHelper
{
    public static function normalizeEnumValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
