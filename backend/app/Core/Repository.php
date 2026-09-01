<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base repository — the ONLY layer allowed to write SQL (§12).
 *
 * ---------------------------------------------------------------------------
 * TENANT ISOLATION (§10)
 * ---------------------------------------------------------------------------
 * The plan says isolation must be enforced "in every authorization path".
 * Doing that with a per-endpoint `WHERE organization_id = ?` written by hand
 * fails the first time someone forgets it — and one forgotten check is a
 * cross-tenant patient-data leak.
 *
 * So isolation is structural here instead of remembered:
 *
 *   - A repository declares `protected bool $tenantScoped = true`.
 *   - Every read and write funnels through scopeWhere(), which INJECTS
 *     `organization_id = :__org` from the tenant bound with forOrganization().
 *   - If a tenant-scoped repository is used before a tenant is bound, it
 *     throws instead of silently querying across all organizations.
 *
 * The only way to query across tenants is the explicit, greppable
 * withoutTenantScope() escape hatch, which exists for the platform admin
 * panel (§21) and for the migration/seed scripts.
 */
abstract class Repository
{
    /** Table name. */
    protected string $table;

    /** Primary key column. */
    protected string $primaryKey = 'id';

    /** When true, every query is constrained to the bound organization. */
    protected bool $tenantScoped = true;

    /** Columns writable through create()/update(). Empty = allow all given. */
    protected array $fillable = [];

    /** Columns never returned by find/get (e.g. password hashes). */
    protected array $hidden = [];

    /** Set to false when the table has no updated_at column. */
    protected bool $timestamps = true;

    private ?int $organizationId = null;
    private bool $scopeDisabled  = false;

    // ---------------------------------------------------------------
    // Tenant binding
    // ---------------------------------------------------------------

    /**
     * Bind the active tenant. Called once per request by the controller/service
     * from Request::organizationId().
     */
    public function forOrganization(?int $organizationId): static
    {
        $this->organizationId = $organizationId;
        return $this;
    }

    /**
     * Deliberately query across all tenants. Only for the super-admin panel
     * (§21) and CLI scripts. Every call site should be obvious in review.
     */
    public function withoutTenantScope(): static
    {
        $this->scopeDisabled = true;
        return $this;
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    protected function scopingActive(): bool
    {
        return $this->tenantScoped && !$this->scopeDisabled;
    }

    /**
     * Merge the tenant constraint into a conditions array.
     *
     * @param array<string,mixed> $conditions
     * @return array<string,mixed>
     */
    protected function scoped(array $conditions): array
    {
        if (!$this->scopingActive()) {
            return $conditions;
        }
        if ($this->organizationId === null) {
            throw new \LogicException(sprintf(
                '%s is tenant-scoped but no organization was bound. '
                . 'Call forOrganization($id), or withoutTenantScope() if crossing '
                . 'tenants is genuinely intended.',
                static::class,
            ));
        }
        $conditions['organization_id'] = $this->organizationId;
        return $conditions;
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->firstWhere([$this->primaryKey => $id]);
        return $row === null ? null : $this->hide($row);
    }

    /**
     * Find or throw 404. Because the tenant filter is part of the WHERE,
     * a row belonging to another organization is indistinguishable from a
     * row that does not exist — which is exactly the right behaviour.
     *
     * @return array<string,mixed>
     */
    public function findOrFail(int $id, string $what = 'Record'): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new NotFoundException($what . ' not found');
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $conditions column => value, or
     *                            column => [operator, value] for comparisons
     * @return array<string,mixed>|null
     */
    public function firstWhere(array $conditions): ?array
    {
        $rows = $this->where($conditions, null, 1);
        return $rows[0] ?? null;
    }

    /**
     * @param array<string,mixed> $conditions
     * @return list<array<string,mixed>>
     */
    public function where(
        array $conditions = [],
        ?string $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        [$sql, $bindings] = $this->buildWhere($this->scoped($conditions));

        $query = 'SELECT * FROM ' . $this->quote($this->table) . $sql;
        if ($orderBy !== null) {
            $query .= ' ORDER BY ' . $this->safeOrderBy($orderBy);
        }
        if ($limit !== null) {
            $query .= ' LIMIT ' . max(0, $limit);
            if ($offset !== null) {
                $query .= ' OFFSET ' . max(0, $offset);
            }
        }

        return array_map([$this, 'hide'], Database::select($query, $bindings));
    }

    /** @param array<string,mixed> $conditions */
    public function count(array $conditions = []): int
    {
        [$sql, $bindings] = $this->buildWhere($this->scoped($conditions));
        $row = Database::selectOne(
            'SELECT COUNT(*) AS aggregate FROM ' . $this->quote($this->table) . $sql,
            $bindings,
        );
        return (int) ($row['aggregate'] ?? 0);
    }

    /** @param array<string,mixed> $conditions */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
    }

    /**
     * Offset pagination with a total count.
     *
     * @param array<string,mixed> $conditions
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function paginate(
        array $conditions = [],
        int $page = 1,
        int $perPage = 25,
        ?string $orderBy = null,
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total   = $this->count($conditions);

        return [
            'data' => $this->where($conditions, $orderBy, $perPage, ($page - 1) * $perPage),
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Escape hatch for reads that genuinely need joins or aggregates.
     * Callers MUST include the tenant predicate themselves; scopeBinding()
     * hands them the bound id so it cannot drift out of sync.
     *
     * @param array<string|int,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    protected function query(string $sql, array $bindings = []): array
    {
        return Database::select($sql, $bindings);
    }

    /**
     * The bound organization id, for use in hand-written queries. Throws if
     * scoping is active and nothing is bound — same guarantee as scoped().
     */
    protected function scopeBinding(): int
    {
        if ($this->organizationId === null) {
            throw new \LogicException(static::class . ': no organization bound.');
        }
        return $this->organizationId;
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    /**
     * Insert and return the new row.
     *
     * organization_id is stamped from the bound tenant, so a caller cannot
     * create a row inside somebody else's organization even by passing one.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function create(array $attributes): array
    {
        $data = $this->filterFillable($attributes);

        if ($this->scopingActive()) {
            $data['organization_id'] = $this->scopeBinding();
        }
        if ($this->timestamps) {
            $data['created_at'] ??= now();
            $data['updated_at'] ??= now();
        }

        $columns      = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

        Database::statement(
            'INSERT INTO ' . $this->quote($this->table)
            . ' (' . implode(', ', array_map([$this, 'quote'], $columns)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')',
            $this->bind($data),
        );

        $id = Database::lastInsertId();
        return $this->find($id) ?? $data;
    }

    /**
     * Update by primary key, within the tenant scope.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function update(int $id, array $attributes): array
    {
        $data = $this->filterFillable($attributes);
        // organization_id is never reassignable through the ORM.
        unset($data['organization_id'], $data[$this->primaryKey]);

        if ($data === []) {
            return $this->findOrFail($id);
        }
        if ($this->timestamps) {
            $data['updated_at'] = now();
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $this->quote($column) . ' = :' . $column;
        }

        $conditions            = $this->scoped([$this->primaryKey => $id]);
        [$whereSql, $whereBind] = $this->buildWhere($conditions, 'w_');

        $affected = Database::statement(
            'UPDATE ' . $this->quote($this->table)
            . ' SET ' . implode(', ', $sets) . $whereSql,
            $this->bind($data) + $whereBind,
        );

        if ($affected === 0 && !$this->exists([$this->primaryKey => $id])) {
            throw new NotFoundException('Record not found');
        }

        return $this->findOrFail($id);
    }

    /** Delete by primary key, within the tenant scope. */
    public function delete(int $id): bool
    {
        [$sql, $bindings] = $this->buildWhere($this->scoped([$this->primaryKey => $id]));
        return Database::statement(
            'DELETE FROM ' . $this->quote($this->table) . $sql,
            $bindings,
        ) > 0;
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Build a WHERE clause with bound parameters.
     *
     * Supported condition shapes:
     *   'status' => 'active'                  -> status = ?
     *   'status' => ['!=', 'cancelled']       -> status != ?
     *   'total'  => ['>=', 100]               -> total >= ?
     *   'status' => ['in', ['a','b']]         -> status IN (?, ?)
     *   'name'   => ['like', '%ali%']         -> name LIKE ?
     *   'deleted_at' => null                  -> deleted_at IS NULL
     *
     * @param array<string,mixed> $conditions
     * @return array{0:string, 1:array<string,mixed>}
     */
    protected function buildWhere(array $conditions, string $prefix = 'b_'): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $clauses  = [];
        $bindings = [];
        $i        = 0;

        foreach ($conditions as $column => $value) {
            $col = $this->quote($column);
            $key = $prefix . $i++;

            if ($value === null) {
                $clauses[] = "$col IS NULL";
                continue;
            }

            if (is_array($value) && count($value) === 2 && is_string($value[0] ?? null)) {
                [$operator, $operand] = $value;
                $operator = strtolower(trim($operator));

                if ($operator === 'in' || $operator === 'not in') {
                    $list = array_values((array) $operand);
                    if ($list === []) {
                        // IN () is invalid SQL; an empty set matches nothing.
                        $clauses[] = $operator === 'in' ? '1 = 0' : '1 = 1';
                        continue;
                    }
                    $names = [];
                    foreach ($list as $n => $item) {
                        $names[]                    = ':' . $key . '_' . $n;
                        $bindings[$key . '_' . $n]  = $item;
                    }
                    $clauses[] = "$col " . strtoupper($operator) . ' (' . implode(', ', $names) . ')';
                    continue;
                }

                $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like'];
                if (!in_array($operator, $allowed, true)) {
                    throw new \InvalidArgumentException("Unsupported operator: $operator");
                }
                $clauses[]      = "$col " . strtoupper($operator) . " :$key";
                $bindings[$key] = $operand;
                continue;
            }

            $clauses[]      = "$col = :$key";
            $bindings[$key] = $value;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function filterFillable(array $data): array
    {
        if ($this->fillable === []) {
            return $data;
        }
        return array_only($data, $this->fillable);
    }

    /**
     * Cast values PDO cannot bind directly (bool, array/JSON).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function bind(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $out[$key] = $value ? 1 : 0;
            } elseif (is_array($value)) {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function hide(array $row): array
    {
        foreach ($this->hidden as $column) {
            unset($row[$column]);
        }
        return $row;
    }

    /** Backtick-quote an identifier, rejecting anything that is not one. */
    protected function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Invalid identifier: $identifier");
        }
        return '`' . $identifier . '`';
    }

    /**
     * ORDER BY cannot be parameterised, so it is validated as
     * "column [ASC|DESC]" pairs and re-emitted from the parsed parts.
     */
    protected function safeOrderBy(string $orderBy): string
    {
        $parts = [];
        foreach (explode(',', $orderBy) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $bits      = preg_split('/\s+/', $segment) ?: [];
            $column    = $this->quote($bits[0]);
            $direction = strtoupper($bits[1] ?? 'ASC');
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException("Invalid sort direction: $direction");
            }
            $parts[] = "$column $direction";
        }
        if ($parts === []) {
            throw new \InvalidArgumentException('Empty ORDER BY');
        }
        return implode(', ', $parts);
    }
}
