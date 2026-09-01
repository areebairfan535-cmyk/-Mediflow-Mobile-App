<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * roles + permissions + role_permissions + organization_users reads.
 *
 * Not tenant-scoped by the base class because roles legitimately span two
 * scopes: system roles (organization_id NULL) act as templates, and each
 * organization may add its own. Queries here are explicit about which they want.
 */
final class RoleRepository extends Repository
{
    protected string $table        = 'roles';
    protected bool   $tenantScoped = false;

    protected array $fillable = [
        'organization_id', 'slug', 'name', 'description', 'is_system',
        'created_at', 'updated_at',
    ];

    /** System role template by slug (organization_id IS NULL). */
    public function findSystemRole(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT * FROM roles WHERE slug = :slug AND organization_id IS NULL LIMIT 1',
            ['slug' => $slug],
        );
    }

    /**
     * Roles assignable inside an organization: its own plus the system ones.
     *
     * @return list<array<string,mixed>>
     */
    public function assignableIn(int $organizationId): array
    {
        return Database::select(
            'SELECT * FROM roles
              WHERE organization_id = :org OR organization_id IS NULL
              ORDER BY is_system DESC, name ASC',
            ['org' => $organizationId],
        );
    }

    /**
     * A role is usable in an organization if it is that organization's own
     * role or a system template. Checked before any role assignment so a
     * caller cannot attach a role belonging to a different tenant.
     */
    public function isUsableIn(int $roleId, int $organizationId): bool
    {
        $row = Database::selectOne(
            'SELECT id FROM roles
              WHERE id = :id
                AND (organization_id = :org OR organization_id IS NULL)
              LIMIT 1',
            ['id' => $roleId, 'org' => $organizationId],
        );
        return $row !== null;
    }

    /** @return list<string> permission slugs */
    public function permissionSlugs(int $roleId): array
    {
        $rows = Database::select(
            'SELECT p.slug
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role
              ORDER BY p.slug',
            ['role' => $roleId],
        );
        return array_column($rows, 'slug');
    }

    /** @return list<array<string,mixed>> */
    public function permissions(int $roleId): array
    {
        return Database::select(
            'SELECT p.*
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role
              ORDER BY p.module, p.slug',
            ['role' => $roleId],
        );
    }

    /**
     * Replace a role's permission set atomically.
     *
     * @param list<string> $permissionSlugs
     */
    public function syncPermissions(int $roleId, array $permissionSlugs): void
    {
        Database::transaction(function () use ($roleId, $permissionSlugs): void {
            Database::statement(
                'DELETE FROM role_permissions WHERE role_id = :role',
                ['role' => $roleId],
            );
            if ($permissionSlugs === []) {
                return;
            }
            $placeholders = [];
            $bindings     = ['role' => $roleId];
            foreach (array_values(array_unique($permissionSlugs)) as $i => $slug) {
                $placeholders[]    = ':s' . $i;
                $bindings['s' . $i] = $slug;
            }
            // INSERT ... SELECT keeps unknown slugs from creating phantom rows.
            Database::statement(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :role, p.id FROM permissions p
                  WHERE p.slug IN (' . implode(', ', $placeholders) . ')',
                $bindings,
            );
        });
    }

    // ---- organization_users ----

    /**
     * Membership row joined with its role slug — the single source of truth
     * for "may this user act inside this organization".
     *
     * @return array<string,mixed>|null
     */
    public function membership(int $userId, int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT ou.*, r.slug AS role_slug, r.name AS role_name
               FROM organization_users ou
               JOIN roles r ON r.id = ou.role_id
              WHERE ou.user_id = :uid AND ou.organization_id = :org
              LIMIT 1',
            ['uid' => $userId, 'org' => $organizationId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function membershipsForUser(int $userId): array
    {
        return Database::select(
            'SELECT ou.organization_id, ou.role_id, ou.status, ou.job_title,
                    r.slug AS role_slug, r.name AS role_name,
                    o.name AS organization_name, o.slug AS organization_slug,
                    o.status AS organization_status
               FROM organization_users ou
               JOIN roles r         ON r.id = ou.role_id
               JOIN organizations o ON o.id = ou.organization_id
              WHERE ou.user_id = :uid
                AND ou.status  = \'active\'
                AND o.status   = \'active\'
              ORDER BY o.name',
            ['uid' => $userId],
        );
    }

    /** @return list<array<string,mixed>> Members of one organization. */
    public function members(int $organizationId): array
    {
        return Database::select(
            'SELECT ou.id, ou.user_id, ou.role_id, ou.status, ou.job_title,
                    ou.joined_at,
                    u.name, u.email, u.phone, u.status AS user_status,
                    r.slug AS role_slug, r.name AS role_name
               FROM organization_users ou
               JOIN users u ON u.id = ou.user_id
               JOIN roles r ON r.id = ou.role_id
              WHERE ou.organization_id = :org
              ORDER BY r.name, u.name',
            ['org' => $organizationId],
        );
    }
}
