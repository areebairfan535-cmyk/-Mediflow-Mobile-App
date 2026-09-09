<?php
declare(strict_types=1);

namespace App\Models;

/** A billable item in the clinic's catalogue (§6). */
final class Service extends Model
{
    public static function table(): string
    {
        return 'services';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'code', 'name', 'description', 'department', 'category',
            'is_taxable', 'is_active', 'created_at', 'updated_at',
        ];
    }
}
