<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../team_helpers.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
}

$mode = (string)($_POST['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$driverId = (int)($_POST['driver_id'] ?? 0);

$team = f1hub_get_team_full($pdo, $userId, $mode);
if (!$team) {
    f1hub_json(['ok' => false, 'error' => 'Maak eerst een team aan in deze modus.'], 404);
}

$driver = null;
foreach ($team['drivers'] as $d) {
    if ((int)$d['id'] === $driverId) { $driver = $d; break; }
}
if (!$driver) {
    f1hub_json(['ok' => false, 'error' => 'Deze coureur zit niet in je team.'], 422);
}

if ((int)$team['budget'] < F1HUB_CONTRACT_RENEWAL_COST) {
    f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget om dit contract te verlengen.'], 422);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE drivers SET contract_races_left = ? WHERE id = ?');
    $stmt->execute([F1HUB_CONTRACT_LENGTH, $driverId]);

    $stmt = $pdo->prepare('UPDATE teams SET budget = budget - ? WHERE id = ?');
    $stmt->execute([F1HUB_CONTRACT_RENEWAL_COST, $team['id']]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    f1hub_json(['ok' => false, 'error' => 'Verlengen is mislukt.'], 500);
}

f1hub_json([
    'ok' => true,
    'driverId' => $driverId,
    'contractRacesLeft' => F1HUB_CONTRACT_LENGTH,
    'budget' => (int)$team['budget'] - F1HUB_CONTRACT_RENEWAL_COST,
]);
