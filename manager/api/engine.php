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
        'engineLevel' => (int)$team['engine_level'],
        'maxLevel' => F1HUB_ENGINE_MAX_LEVEL,
        'budget' => (int)$team['budget'],
        'nextCost' => f1hub_next_engine_cost((int)$team['engine_level']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $level = (int)$team['engine_level'];
    $cost = f1hub_next_engine_cost($level);
    if ($cost === null) {
        f1hub_json(['ok' => false, 'error' => 'De motor zit al op het maximale niveau.'], 422);
    }
    if ((int)$team['budget'] < $cost) {
        f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget voor deze upgrade.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE teams SET engine_level = engine_level + 1, budget = budget - ? WHERE id = ?');
    $stmt->execute([$cost, $team['id']]);

    $updated = f1hub_get_team_full($pdo, $userId, $mode);
    f1hub_json([
        'ok' => true,
        'engineLevel' => (int)$updated['engine_level'],
        'maxLevel' => F1HUB_ENGINE_MAX_LEVEL,
        'budget' => (int)$updated['budget'],
        'nextCost' => f1hub_next_engine_cost((int)$updated['engine_level']),
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
