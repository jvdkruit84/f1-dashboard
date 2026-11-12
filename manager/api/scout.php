<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../driver_pool.php';
require __DIR__ . '/../team_helpers.php';

const F1HUB_SCOUT_COST = 8;

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

function f1hub_scouted_map($pdo, $teamId) {
    $stmt = $pdo->prepare('SELECT pool_driver_id, asking_price FROM scouted_drivers WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['pool_driver_id']] = (int)$row['asking_price'];
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scouted = f1hub_scouted_map($pdo, $team['id']);
    $pool = array_map(function ($d) use ($scouted) {
        if (isset($scouted[$d['id']])) {
            return [
                'id' => $d['id'], 'name' => $d['name'], 'scouted' => true,
                'pace' => $d['pace'], 'consistency' => $d['consistency'], 'askingPrice' => $scouted[$d['id']],
            ];
        }
        return ['id' => $d['id'], 'name' => $d['name'], 'scouted' => false];
    }, F1HUB_DRIVER_POOL);

    f1hub_json(['ok' => true, 'budget' => (int)$team['budget'], 'scoutCost' => F1HUB_SCOUT_COST, 'pool' => $pool]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $poolDriverId = (int)($_POST['pool_driver_id'] ?? 0);
    $poolDriver = f1hub_driver_from_pool($poolDriverId);
    if (!$poolDriver) {
        f1hub_json(['ok' => false, 'error' => 'Onbekende coureur.'], 422);
    }
    if ((int)$team['budget'] < F1HUB_SCOUT_COST) {
        f1hub_json(['ok' => false, 'error' => 'Onvoldoende budget om te scouten.'], 422);
    }

    $stmt = $pdo->prepare('SELECT asking_price FROM scouted_drivers WHERE team_id = ? AND pool_driver_id = ?');
    $stmt->execute([$team['id'], $poolDriverId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        f1hub_json(['ok' => false, 'error' => 'Deze coureur is al gescout.'], 409);
    }

    $askingPrice = (int)round($poolDriver['price'] * (0.8 + mt_rand() / mt_getrandmax() * 0.5));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO scouted_drivers (team_id, pool_driver_id, asking_price) VALUES (?, ?, ?)');
        $stmt->execute([$team['id'], $poolDriverId, $askingPrice]);

        $stmt = $pdo->prepare('UPDATE teams SET budget = budget - ? WHERE id = ?');
        $stmt->execute([F1HUB_SCOUT_COST, $team['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        f1hub_json(['ok' => false, 'error' => 'Scouten mislukt.'], 500);
    }

    f1hub_json([
        'ok' => true,
        'budget' => (int)$team['budget'] - F1HUB_SCOUT_COST,
        'driver' => [
            'id' => $poolDriver['id'], 'name' => $poolDriver['name'], 'scouted' => true,
            'pace' => $poolDriver['pace'], 'consistency' => $poolDriver['consistency'], 'askingPrice' => $askingPrice,
        ],
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
