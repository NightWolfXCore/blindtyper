-- BlindTyper database schema v1
-- Run against an empty MySQL 8.0+ database.

CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(254) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(40) NOT NULL,
    `total_xp` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `achievements` (
    `code` VARCHAR(50) NOT NULL,
    `title` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `rule_key` VARCHAR(50) NOT NULL,
    `threshold` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `exercises` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mode` VARCHAR(20) NOT NULL,
    `language` VARCHAR(10) NOT NULL,
    `difficulty` VARCHAR(20) NOT NULL,
    `code_language` VARCHAR(20) DEFAULT NULL,
    `text` TEXT NOT NULL,
    `text_length` INT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_exercises_search` (`mode`, `language`, `difficulty`, `active`, `text_length`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `schema_migrations` (
    `version` VARCHAR(50) NOT NULL,
    `applied_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `training_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `exercise_id` BIGINT UNSIGNED DEFAULT NULL,
    `mode` VARCHAR(20) NOT NULL,
    `language` VARCHAR(10) NOT NULL,
    `difficulty` VARCHAR(20) NOT NULL,
    `code_language` VARCHAR(20) DEFAULT NULL,
    `text_snapshot` TEXT NOT NULL,
    `text_length` INT UNSIGNED NOT NULL,
    `rules_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `bonuses` JSON DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'created',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NOT NULL,
    `started_at` DATETIME(3) DEFAULT NULL,
    `finished_at` DATETIME(3) DEFAULT NULL,
    `duration_ms` BIGINT UNSIGNED DEFAULT NULL,
    `correct_count` INT UNSIGNED DEFAULT NULL,
    `error_count` INT UNSIGNED DEFAULT NULL,
    `max_combo` INT UNSIGNED DEFAULT NULL,
    `xp_earned` BIGINT UNSIGNED DEFAULT NULL,
    `ranked` TINYINT(1) NOT NULL DEFAULT 0,
    `unranked_reason` VARCHAR(30) DEFAULT NULL,
    `events_hash` CHAR(64) DEFAULT NULL,
    `finish_response` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `public_id` (`public_id`),
    KEY `idx_sessions_user_history` (`user_id`, `status`, `finished_at`, `id`),
    KEY `idx_sessions_leaderboard` (`status`, `ranked`, `finished_at`, `user_id`),
    KEY `idx_sessions_leaderboard_filter` (`mode`, `language`, `status`, `ranked`, `finished_at`, `user_id`),
    KEY `fk_sessions_exercise` (`exercise_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `session_character_stats` (
    `session_id` BIGINT UNSIGNED NOT NULL,
    `expected_char` VARCHAR(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `errors` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`session_id`, `expected_char`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_achievements` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `achievement_code` VARCHAR(50) NOT NULL,
    `session_id` BIGINT UNSIGNED DEFAULT NULL,
    `unlocked_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`user_id`, `achievement_code`),
    KEY `fk_user_achievements_achievement` (`achievement_code`),
    KEY `fk_user_achievements_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `session_character_stats`
    ADD CONSTRAINT `fk_character_stats_session`
    FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`)
    ON DELETE CASCADE;

ALTER TABLE `training_sessions`
    ADD CONSTRAINT `fk_sessions_exercise`
    FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`)
    ON DELETE SET NULL,
    ADD CONSTRAINT `fk_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

ALTER TABLE `user_achievements`
    ADD CONSTRAINT `fk_user_achievements_achievement`
    FOREIGN KEY (`achievement_code`) REFERENCES `achievements` (`code`),
    ADD CONSTRAINT `fk_user_achievements_session`
    FOREIGN KEY (`session_id`) REFERENCES `training_sessions` (`id`),
    ADD CONSTRAINT `fk_user_achievements_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
