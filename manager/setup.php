<?php
// One-off migration: creates the manager tables if they don't exist yet.
// Protected by the existing admin password. Safe to re-run (idempotent).
require __DIR__ . '/db.php';
$configFile = __DIR__ . '/../admin-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    die("admin-config.php ontbreekt.\n");
}
require $configFile;

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals(ADMIN_PASSWORD, (string)($_GET['password'] ?? ''))) {
    http_response_code(403);
    die("Verkeerd wachtwoord. Gebruik ?password=...\n");
}

$pdo = f1hub_db();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    mode ENUM('season','career') NOT NULL,
    name VARCHAR(60) NOT NULL,
    color_hex CHAR(6) NOT NULL DEFAULT 'e10600',
    car_rating TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_mode (user_id, mode),
    CONSTRAINT fk_teams_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS drivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    pace TINYINT UNSIGNED NOT NULL,
    consistency TINYINT UNSIGNED NOT NULL,
    CONSTRAINT fk_drivers_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS race_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    mode ENUM('season','career') NOT NULL,
    race_key VARCHAR(40) NOT NULL,
    strategy VARCHAR(30) NOT NULL,
    finish_position TINYINT UNSIGNED NOT NULL,
    points TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_team_race (team_id, race_key),
    CONSTRAINT fk_results_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS replay_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    race_key VARCHAR(40) NOT NULL,
    race_name VARCHAR(120) NOT NULL,
    constructor_name VARCHAR(60) NOT NULL,
    strategy VARCHAR(30) NOT NULL,
    real_position TINYINT UNSIGNED NOT NULL,
    sim_position TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_replay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS scouted_drivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id INT UNSIGNED NOT NULL,
    pool_driver_id TINYINT UNSIGNED NOT NULL,
    asking_price INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_team_driver (team_id, pool_driver_id),
    CONSTRAINT fk_scouted_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// idempotente kolom-toevoeging (veilig op een DB met bestaande teams/spelers)
function f1hub_add_column_if_missing($pdo, $table, $column, $definition) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        echo "Kolom '{$column}' toegevoegd aan {$table}.\n";
    } else {
        echo "Kolom '{$column}' bestond al.\n";
    }
}

f1hub_add_column_if_missing($pdo, 'teams', 'budget', 'budget INT UNSIGNED NOT NULL DEFAULT 50 AFTER car_rating');
f1hub_add_column_if_missing($pdo, 'teams', 'engine_level', 'engine_level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER budget');
f1hub_add_column_if_missing($pdo, 'teams', 'aero_level', 'aero_level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER engine_level');

echo "Setup voltooid: users, teams, drivers, race_results, replay_attempts, scouted_drivers zijn aangemaakt (of bestonden al).\n";
