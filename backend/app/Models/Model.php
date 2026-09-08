<?php
declare(strict_types=1);

namespace App\Models;

/**
 * What a record IS — as against how it is fetched, which is the repository's
 * job (§18).
 *
 * The two had been the same class. Every repository declared its own $table,
 * $fillable and $hidden inline, which meant the shape of a row and the way it
 * is queried were one lump: nothing could describe `patients` without also
 * being the thing that runs SELECTs against it, and the model layer §18 asks
 * for existed as an empty directory.
 *
 * So the description moved here, and the Repository now reads it:
 *
 *      final class PatientRepository extends Repository
 *      {
 *          protected string $model = Patient::class;
 *      }
 *
 * A model states four things, and none of them involve a database connection:
 *
 *  - **table / primaryKey** — where the record lives.
 *  - **fillable** — which columns a caller may write. Anything not listed is
 *    dropped rather than rejected, so a client sending an extra field cannot
 *    reach a column the domain never meant to expose.
 *  - **hidden** — columns that must never leave the layer. Password hashes and
 *    token hashes are here, and that is why "which fields are secret" is
 *    answerable by reading one short file instead of auditing every query.
 *  - **tenantScoped** — whether rows belong to one clinic (§10). Getting this
 *    wrong is a cross-tenant leak, so it is stated once, next to the table
 *    name, rather than remembered at each call site.
 *
 * Static because a model describes a table, not one row: there is nothing to
 * instantiate, and no state to get out of step with the database.
 */
abstract class Model
{
    /** The table this record lives in. */
    abstract public static function table(): string;

    /** Columns a caller may write through create()/update(). */
    abstract public static function fillable(): array;

    public static function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Does a row belong to exactly one clinic?
     *
     * True for almost everything — the exceptions are the tenant roots
     * themselves (organizations, users) and platform-wide reference data
     * (plans, countries, system roles).
     */
    public static function tenantScoped(): bool
    {
        return true;
    }

    /** False for append-only tables that carry created_at and nothing else. */
    public static function timestamps(): bool
    {
        return true;
    }

    /**
     * Columns never returned by find/get.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        return [];
    }
}
