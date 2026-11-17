-- Retire unsupported historical staff accounts without deleting users or audit history.
-- Review and back up the target database before applying this to an existing installation.
-- Fresh installations from schema.sql retain Manager and Server as inactive history.
USE `kenjis_kitchen`;

UPDATE `users` u
JOIN `roles` r ON r.`id` = u.`role_id`
SET u.`status` = 'Inactive'
WHERE r.`role_name` IN ('Manager', 'Server')
  AND u.`status` <> 'Inactive';
