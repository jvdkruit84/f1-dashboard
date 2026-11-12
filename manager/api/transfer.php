<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../driver_pool.php';
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
    f1hub_json(['ok' => true, 'team' => $team, 'driverPool' => F1HUB_DRIVER_POOL]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $releaseDriverId = (int)($_POST['release_driver_id'] ?? 0);
    $poolDriverId = (int)($_POST['pool_driver_id'] ?? 0);

    $releaseDriver = null;
    $keepDriver = null;
    foreach ($team['drivers'] as $d) {
        if ((int)$d['id'] === $releaseDriverId) $releaseDriver = $d;
        else $keepDriver = $d;
    }
    if (!$releaseDriver) {
        f1hub_json(['ok' => false, 'error' => 'Die coureur hoort niet bij jouw team.'], 422);
    }

    $poolDriver = f1hub_driver_from_pool($poolDriverId);
    if (!$poolDriver) {
        f1hub_json(['ok' => false, 'error' => 'Onbekende coureur gekozen.'], 422);
    }
    if ($keepDriver && $keepDriver['name'] === $poolDriver['name']) {
        f1hub_json(['ok' => false, 'error' => 'Deze coureur zit al in je team.'], 422);
    }
    if ((int)$team['budget'] < $poolDriver['price']) {
        f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget voor deze transfer.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM drivers WHERE id = ? AND team_id = ?');
        $stmt->execute([$releaseDriverId, $team['id']]);

        $stmt = $pdo->prepare('INSERT INTO drivers (team_id, name, pace, consistency) VALUES (?, ?, ?, ?)');
        $stmt->execute([$team['id'], $poolDriver['name'], $poolDriver['pace'], $poolDriver['consistency']]);

        $stmt = $pdo->prepare('UPDATE teams SET budget = budget - ? WHERE id = ?');
        $stmt->execute([$poolDriver['price'], $team['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        f1hub_json(['ok' => false, 'error' => 'Transfer mislukt.'], 500);
    }

    $updated = f1hub_get_team_full($pdo, $userId, $mode);
    f1hub_json(['ok' => true, 'team' => $updated]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
