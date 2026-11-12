<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../team_helpers.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

const F1HUB_HQ_BUILDINGS = [
    'windtunnel' => ['column' => 'hq_windtunnel_level', 'label' => 'Windtunnel'],
    'cfd'        => ['column' => 'hq_cfd_level',        'label' => 'CFD-simulator'],
    'simulator'  => ['column' => 'hq_simulator_level',  'label' => 'Racesimulator'],
    'pitcrew'    => ['column' => 'hq_pitcrew_level',    'label' => 'Pitcrew-faciliteiten'],
];

$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$team = f1hub_get_team_full($pdo, $userId, $mode);
if (!$team) {
    f1hub_json(['ok' => false, 'error' => 'Maak eerst een team aan in deze modus.'], 404);
}

function f1hub_hq_state($team) {
    $buildings = [];
    foreach (F1HUB_HQ_BUILDINGS as $key => $info) {
        $level = (int)$team[$info['column']];
        $buildings[$key] = [
            'label' => $info['label'],
            'level' => $level,
            'maxLevel' => F1HUB_PART_MAX_LEVEL,
            'nextCost' => f1hub_next_part_cost($level),
        ];
    }
    return $buildings;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    f1hub_json([
        'ok' => true,
        'budget' => (int)$team['budget'],
        'colorHex' => $team['color_hex'],
        'buildings' => f1hub_hq_state($team),
        'drivers' => $team['drivers'],
        'simulatorLevel' => (int)$team['hq_simulator_level'],
        'trainCost' => F1HUB_TRAINING_COST,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $building = (string)($_POST['building'] ?? '');
    if (!array_key_exists($building, F1HUB_HQ_BUILDINGS)) {
        f1hub_json(['ok' => false, 'error' => 'Onbekend gebouw.'], 422);
    }
    $column = F1HUB_HQ_BUILDINGS[$building]['column'];
    $level = (int)$team[$column];
    $cost = f1hub_next_part_cost($level);
    if ($cost === null) {
        f1hub_json(['ok' => false, 'error' => F1HUB_HQ_BUILDINGS[$building]['label'] . ' zit al op het maximale niveau.'], 422);
    }
    if ((int)$team['budget'] < $cost) {
        f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget voor deze upgrade.'], 422);
    }

    $stmt = $pdo->prepare("UPDATE teams SET {$column} = {$column} + 1, budget = budget - ? WHERE id = ?");
    $stmt->execute([$cost, $team['id']]);

    $updated = f1hub_get_team_full($pdo, $userId, $mode);
    f1hub_json([
        'ok' => true,
        'budget' => (int)$updated['budget'],
        'buildings' => f1hub_hq_state($updated),
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
