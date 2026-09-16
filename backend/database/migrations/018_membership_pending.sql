-- A doctor who applies from the login page waits at the door (§2, §9).
--
-- Memberships were only ever created by an owner or by an invitation, so
-- `active` and `invited` were the only ways in. A doctor can now register
-- themselves against a clinic; that membership is `pending` — the login
-- works, the clinic does not open — until an owner approves it. `rejected`
-- keeps the refused application so the person is told, not just locked out.

ALTER TABLE `organization_users`
    MODIFY COLUMN `status` ENUM('active','invited','disabled','pending','rejected')
        NOT NULL DEFAULT 'active';
