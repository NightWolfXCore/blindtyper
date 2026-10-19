-- Development/test compatibility fixture. Production migrations belong to the database owner.
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(40) NOT NULL,
 total_xp BIGINT UNSIGNED NOT NULL DEFAULT 0, is_demo BOOLEAN NOT NULL DEFAULT FALSE,
 created_at DATETIME(3) NOT NULL, updated_at DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS exercises (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 mode VARCHAR(16) NOT NULL, language VARCHAR(2) NOT NULL, difficulty VARCHAR(8) NOT NULL,
 code_language VARCHAR(16) NULL, text TEXT NOT NULL, text_length INT UNSIGNED NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE, created_at DATETIME(3) NOT NULL,
 INDEX exercise_selection (mode,language,difficulty,active,text_length)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS training_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL, exercise_id BIGINT UNSIGNED NULL,
 mode VARCHAR(16) NOT NULL, language VARCHAR(2) NOT NULL, difficulty VARCHAR(8) NOT NULL, code_language VARCHAR(16) NULL,
 text_snapshot TEXT NOT NULL, text_length INT UNSIGNED NOT NULL, rules_version INT UNSIGNED NOT NULL,
 bonuses JSON NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME(3) NOT NULL, expires_at DATETIME(3) NOT NULL,
 started_at DATETIME(3) NULL, finished_at DATETIME(3) NULL, duration_ms INT UNSIGNED NULL,
 correct_count INT UNSIGNED NULL, error_count INT UNSIGNED NULL, max_combo INT UNSIGNED NULL, xp_earned BIGINT UNSIGNED NULL,
 ranked BOOLEAN NOT NULL DEFAULT FALSE, unranked_reason VARCHAR(16) NULL,
 events_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, finish_response JSON NULL,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE SET NULL,
 INDEX user_history (user_id,status,finished_at,id), INDEX general_ranking (status,ranked,finished_at,user_id),
 INDEX filtered_ranking (mode,language,status,ranked,finished_at,user_id),
 CHECK (status IN ('created','started','finished','abandoned','expired')),
 CHECK (status <> 'finished' OR (duration_ms > 0 AND correct_count > 0 AND error_count IS NOT NULL AND xp_earned IS NOT NULL AND finish_response IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS session_character_stats (
 session_id BIGINT UNSIGNED NOT NULL, expected_char VARCHAR(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
 attempts INT UNSIGNED NOT NULL, errors INT UNSIGNED NOT NULL,
 PRIMARY KEY (session_id,expected_char), FOREIGN KEY (session_id) REFERENCES training_sessions(id), CHECK (errors <= attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS achievements (
 code VARCHAR(40) PRIMARY KEY, title VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL,
 rule_key VARCHAR(40) NOT NULL, threshold INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS user_achievements (
 user_id BIGINT UNSIGNED NOT NULL, achievement_code VARCHAR(40) NOT NULL, session_id BIGINT UNSIGNED NOT NULL,
 unlocked_at DATETIME(3) NOT NULL, PRIMARY KEY (user_id,achievement_code),
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (achievement_code) REFERENCES achievements(code),
 FOREIGN KEY (session_id) REFERENCES training_sessions(id), INDEX achievement_time (user_id,unlocked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY,applied_at DATETIME(3) NOT NULL)
 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
