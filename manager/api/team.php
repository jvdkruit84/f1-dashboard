<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../driver_pool.php';
require __DIR__ . '/../real_teams.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, mode, name, color_hex, car_rating, budget FROM teams WHERE user_id = ?');
    $stmt->execute([$userId]);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$team) {
        $dstmt = $pdo->prepare('SELECT name, pace, consistency FROM drivers WHERE team_id = ?');
        $dstmt->execute([$team['id']]);
        $team['drivers'] = $dstmt->fetchAll();
    }
    unset($team);

    f1hub_json(['ok' => true, 'teams' => $teams, 'driverPool' => F1HUB_DRIVER_POOL]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['mode'] ?? '');
    $source = (string)($_POST['source'] ?? 'custom');

    if (!in_array($mode, ['season', 'career'], true)) {
        f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
    }

    if ($source === 'real') {
        $constructorId = (string)($_POST['constructor_id'] ?? '');
        $year = (int)date('Y');
        $realTeam = null;
        foreach (f1hub_fetch_real_teams($year) as $t) {
            if ($t['constructorId'] === $constructorId) { $realTeam = $t; break; }
        }
        if (!$realTeam) {
            f1hub_json(['ok' => false, 'error' => 'Onbekend team.'], 422);
        }
        $name = $realTeam['name'];
        $colorHex = $realTeam['colorHex'];
        $carRating = $realTeam['carRating'];
        [$driverA, $driverB] = $realTeam['drivers'];
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $colorHex = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)($_POST['color_hex'] ?? '')));
        $driverIdA = (int)($_POST['driver_id_a'] ?? 0);
        $driverIdB = (int)($_POST['driver_id_b'] ?? 0);

        if ($name === '' || strlen($name) > 60) {
            f1hub_json(['ok' => false, 'error' => 'Teamnaam is verplicht (max 60 tekens).'], 422);
        }
        if (strlen($colorHex) !== 6) {
            $colorHex = 'e10600';
        }
        if ($driverIdA === $driverIdB) {
            f1hub_json(['ok' => false, 'error' => 'Kies twee verschillende coureurs.'], 422);
        }
        $driverA = f1hub_driver_from_pool($driverIdA);
        $driverB = f1hub_driver_from_pool($driverIdB);
        if (!$driverA || !$driverB) {
            f1hub_json(['ok' => false, 'error' => 'Onbekende coureur gekozen.'], 422);
        }
        $carRating = mt_rand(60, 85);
    }

    $stmt = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? AND mode = ?');
    $stmt->execute([$userId, $mode]);
    if ($stmt->fetch()) {
        f1hub_json(['ok' => false, 'error' => 'Je hebt al een team in deze modus.'], 409);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO teams (user_id, mode, name, color_hex, car_rating) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $mode, $name, $colorHex, $carRating]);
        $teamId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO drivers (team_id, name, pace, consistency) VALUES (?, ?, ?, ?)');
        $stmt->execute([$teamId, $driverA['name'], $driverA['pace'], $driverA['consistency']]);
        $stmt->execute([$teamId, $driverB['name'], $driverB['pace'], $driverB['consistency']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        f1hub_json(['ok' => false, 'error' => 'Aanmaken van team mislukt.'], 500);
    }

    f1hub_json(['ok' => true, 'teamId' => $teamId, 'carRating' => $carRating]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
