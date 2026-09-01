<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection holder.
 *
 * Every query in the application goes through here with bound parameters —
 * that is the SQL-injection protection the plan requires (§17). No layer
 * above ever concatenates user input into SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @param array<string,mixed> $config */
    public static function init(array $config): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'],
        );

        try {
            self::$pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, not client-side emulation.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Strict mode: silent truncation of clinical/financial data is
            // unacceptable, so make MySQL reject bad values instead.
            self::$pdo->exec(
                "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'"
            );

            // ---------------------------------------------------------------
            // Time handling. The application stores UTC everywhere (now() is
            // gmdate) and renders in the organization's timezone at the edge —
            // required by §23, where one deployment serves PK, US, GB and AE.
            //
            // Pinning the session to +00:00 makes NOW(), CURRENT_TIMESTAMP and
            // UTC_TIMESTAMP() agree with what PHP writes. Without it the server
            // uses the host timezone, and comparisons like
            // `expires_at > UTC_TIMESTAMP()` silently mis-fire by the host's
            // UTC offset — which expires every session token early.
            //
            // Note this is why the schema uses DATETIME, not TIMESTAMP:
            //   - TIMESTAMP columns convert on read/write using the session
            //     timezone, so the same row reads differently from a differently
            //     configured client;
            //   - and MySQL implicitly attaches DEFAULT CURRENT_TIMESTAMP
            //     ON UPDATE CURRENT_TIMESTAMP to the FIRST TIMESTAMP column of a
            //     table (explicit_defaults_for_timestamp is off by default in
            //     MariaDB 10.4). That silently rewrote auth_tokens.expires_at on
            //     every UPDATE of the row, killing tokens after a single use.
            // DATETIME has neither behaviour.
            // ---------------------------------------------------------------
            self::$pdo->exec("SET SESSION time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database::init() was never called.');
        }
        return self::$pdo;
    }

    /**
     * Run a prepared statement and return all rows.
     *
     * @param array<string|int, mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        [$sql, $bindings] = self::expandRepeatedPlaceholders($sql, $bindings);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bindings);
        /** @var list<array<string,mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Run a prepared statement and return the first row, or null.
     *
     * @param array<string|int, mixed> $bindings
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        [$sql, $bindings] = self::expandRepeatedPlaceholders($sql, $bindings);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bindings);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Execute a write and return the number of affected rows.
     *
     * @param array<string|int, mixed> $bindings
     */
    public static function statement(string $sql, array $bindings = []): int
    {
        [$sql, $bindings] = self::expandRepeatedPlaceholders($sql, $bindings);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    /**
     * Native prepared statements (EMULATE_PREPARES = false) do not allow the
     * same named placeholder to appear twice, but writing
     * `VALUES (..., :now, :now)` is both natural and correct SQL. Rather than
     * force every call site to invent :now1/:now2 — a rule someone will
     * forget — each repeat is rewritten to a unique name here and given a
     * copy of the same bound value.
     *
     * Emulation stays OFF: parameters are still sent to the server separately
     * from the statement, which is what makes injection impossible.
     *
     * A placeholder-looking token that has no matching binding is left alone,
     * so `::CAST` syntax and text containing a colon are unaffected.
     *
     * @param array<string|int, mixed> $bindings
     * @return array{0:string, 1:array<string|int, mixed>}
     */
    private static function expandRepeatedPlaceholders(string $sql, array $bindings): array
    {
        // Positional (?) bindings have no names to collide.
        if ($bindings === [] || array_is_list($bindings)) {
            return [$sql, $bindings];
        }

        $seen  = [];
        $bound = [];

        $rewritten = preg_replace_callback(
            // (?<!:) skips the second colon of a :: cast.
            '/(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $match) use (&$seen, &$bound, $bindings): string {
                $name = $match[1];

                if (!array_key_exists($name, $bindings)) {
                    return $match[0];
                }

                $occurrence  = $seen[$name] = ($seen[$name] ?? 0) + 1;
                $placeholder = $occurrence === 1
                    ? $name
                    : $name . '__r' . $occurrence;

                $bound[$placeholder] = $bindings[$name];

                return ':' . $placeholder;
            },
            $sql,
        );

        return [$rewritten ?? $sql, $bound];
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction. Rolls back on any exception.
     *
     * Financial writes (invoice + items + totals, payment + invoice status)
     * must be atomic, so services wrap them in this.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        // Nested calls join the outer transaction instead of starting a new one.
        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
