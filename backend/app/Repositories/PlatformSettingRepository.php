<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\PlatformSetting;

/**
 * Deployment-wide settings (§21). Moved out of PlatformSettings per §18.
 */
final class PlatformSettingRepository extends Repository
{
    protected string $model = PlatformSetting::class;

    /**
     * Every stored setting as key => value.
     *
     * Returns an empty map rather than throwing when the table is not there:
     * before migration 012 has run, callers should fall back to environment
     * and defaults, which is exactly how the app behaved before this table
     * existed. A missing table is a deployment state, not a failure.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        try {
            $rows = Database::select('SELECT setting_key, setting_value FROM platform_settings');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $out;
    }

    /** Write one setting, inserting it the first time and replacing it after. */
    public function put(string $key, string $value, ?int $actorId): void
    {
        Database::statement(
            'INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:k, :v, :by, :now)
             ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by = :by2, updated_at = :now2',
            [
                'k'  => $key, 'v'  => $value, 'by'  => $actorId, 'now'  => now(),
                'v2' => $value, 'by2' => $actorId, 'now2' => now(),
            ],
        );
    }
}
