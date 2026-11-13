<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../team_helpers.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$team = f1hub_get_team_full($pdo, $userId, $mode);
if (!$team) {
    f1hub_json(['ok' => false, 'error' => 'Maak eerst een team aan in deze modus.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    f1hub_json([
        'ok' => true,
        'level' => (int)$team['chassis_level'],
        'maxLevel' => F1HUB_PART_MAX_LEVEL,
        'projectRacesLeft' => (int)$team['chassis_project_races_left'],
        'budget' => (int)$team['budget'],
        'nextCost' => f1hub_next_part_cost((int)$team['chassis_level']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int)$team['chassis_project_races_left'] > 0) {
        f1hub_json(['ok' => false, 'error' => 'Er loopt al een chassisproject.'], 422);
    }
    $level = (int)$team['chassis_level'];
    $cost = f1hub_next_part_cost($level);
    if ($cost === null) {
        f1hub_json(['ok' => false, 'error' => 'Het chassis zit al op het maximale niveau.'], 422);
    }
    if ((int)$team['budget'] < $cost) {
        f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget voor dit project.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE teams SET chassis_project_races_left = ?, budget = budget - ? WHERE id = ?');
    $stmt->execute([F1HUB_CHASSIS_PROJECT_DURATION, $cost, $team['id']]);

    $updated = f1hub_get_team_full($pdo, $userId, $mode);
    f1hub_json([
        'ok' => true,
        'level' => (int)$updated['chassis_level'],
        'maxLevel' => F1HUB_PART_MAX_LEVEL,
        'projectRacesLeft' => (int)$updated['chassis_project_races_left'],
        'budget' => (int)$updated['budget'],
        'nextCost' => f1hub_next_part_cost((int)$updated['chassis_level']),
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
