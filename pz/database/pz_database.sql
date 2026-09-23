-- pz's own internal tables (login/session/role infrastructure, plus the shared `jobs` table).
-- Hand-maintained, not a raw phpMyAdmin export - loaded unconditionally by
-- BaseAdminController::generateApplicationDatabase() for every consuming app, before any of that
-- app's own Model-generated tables. Each table declares its own PRIMARY KEY/AUTO_INCREMENT inline
-- rather than via separate ALTER TABLE statements at the end of the file, on purpose: the old
-- three-section layout (CREATE TABLE, then ADD PRIMARY KEY, then MODIFY ... AUTO_INCREMENT) meant
-- a table's definition was split across three places that had to be kept in sync by hand, and
-- every extra statement was one more opportunity to run into the mysqli::multi_query() bug this
-- replaced (see BaseAdminController::generateApplicationDatabase()).

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Structure de la table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `user_id` int,
  `ip` char(45) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `nonces`
--

CREATE TABLE `nonces` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `user_id` int NOT NULL,
  `nonce` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `expiration` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `roles`
--

CREATE TABLE `roles` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `description` text,
  `is_active` tinyint NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int AUTO_INCREMENT PRIMARY KEY,
  `permission_name` varchar(255) NOT NULL,
  `is_active` tinyint NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `jobs`
--
-- Replaces task_runs: both a completed recurring-task run (kind = 'scheduled_task') and an ad hoc
-- job's lifecycle (kind = 'ad_hoc_job') are rows here, so both get the same tracking capability
-- and are queryable the same way. Column shape mirrors pz\Models\Job (pz/jobs/job.php) exactly.
--

CREATE TABLE `jobs` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `user_id` int NOT NULL,
  `kind` char(255) NOT NULL,
  `type` char(255) NOT NULL,
  `handler_controller` char(255) NOT NULL,
  `handler_method` char(255) NOT NULL,
  `status` char(255) NOT NULL,
  `payload` text,
  `total` int,
  `processed` int NOT NULL,
  `message` char(255),
  `error_message` text,
  `attempts` int NOT NULL,
  `started_at` datetime,
  `locked_at` datetime,
  `finished_at` datetime,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `password` char(255) NOT NULL,
  `email` char(255) NOT NULL,
  `username` char(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int NOT NULL,
  `role_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Structure de la table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `user_id` int NOT NULL,
  `token` varchar(255) NOT NULL,
  `issued_at` datetime NOT NULL,
  `expiration` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

COMMIT;
