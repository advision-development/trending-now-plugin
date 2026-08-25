-- The stand-in "owned network site" needs its own schema.
--
-- The image only creates the one database named in MARIADB_DATABASE, and the
-- source container comes up pointing at a second one. Without this it starts,
-- fails to find `wordpress_source`, and the failure reads as a WordPress
-- problem rather than a missing schema.
CREATE DATABASE IF NOT EXISTS wordpress_source
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON wordpress_source.* TO 'wordpress'@'%';

FLUSH PRIVILEGES;
