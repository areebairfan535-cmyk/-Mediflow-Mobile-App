-- 008_role_uniqueness.sql
--
-- One row per role, per scope.
--
-- `roles` already carried UNIQUE (organization_id, slug), which does its job
-- for a clinic's own custom roles — but the ten system roles from §10 have
-- organization_id NULL, and in SQL two NULLs are not equal. The unique key
-- therefore never fired for them, and every re-run of seed.php inserted a
-- fresh copy of all ten instead of updating them (its ON DUPLICATE KEY UPDATE
-- had nothing to collide with).
--
-- That is not merely untidy. Only the first copy of each role holds the
-- permission grants; the duplicates have none. Assign somebody the second
-- "receptionist" — which is what a role picker showing every row invites —
-- and they can sign in and do nothing at all, with no error to explain why.
--
-- So: fold the duplicates back onto the lowest id, then key the uniqueness on
-- a stored column that treats "no organization" as a scope of its own.

-- 1. Move anybody sitting on a duplicate back to the canonical role.
UPDATE organization_users ou
   SET ou.role_id = (
       SELECT MIN(canonical.id)
         FROM roles canonical
         JOIN roles current ON current.id = ou.role_id
        WHERE canonical.slug = current.slug
          AND IFNULL(canonical.organization_id, 0) = IFNULL(current.organization_id, 0)
   );

-- 2. Drop the copies. role_permissions rows for them cascade away.
DELETE duplicate
  FROM roles duplicate
  JOIN roles canonical
    ON canonical.slug = duplicate.slug
   AND IFNULL(canonical.organization_id, 0) = IFNULL(duplicate.organization_id, 0)
   AND canonical.id < duplicate.id;

-- 3. Make it impossible to do again. A stored generated column, because the
--    NULL is the whole problem: system roles collapse to scope 0 and collide
--    with each other exactly as clinic-scoped roles already do.
ALTER TABLE roles
    ADD COLUMN org_scope BIGINT UNSIGNED
        GENERATED ALWAYS AS (IFNULL(organization_id, 0)) STORED,
    ADD UNIQUE KEY uniq_role_scope_key (org_scope, slug);
