<?php
declare(strict_types=1);

namespace App\Models;

/** The clinic's drug list (§9). */
final class Medication extends Model
{
    public static function table(): string
    {
        return 'medications';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'name', 'brand_name', 'form', 'strength',
            'default_dosage', 'default_frequency', 'default_duration',
            'is_active', 'created_at', 'updated_at',
        ];
    }
}
