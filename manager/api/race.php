<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../f1_calendar.php';
require __DIR__ . '/../simulate.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

function f1hub_get_team($pdo, $userId, $mode) {
    $stmt = $pdo->prepare('SELECT id, name, color_hex, car_rating FROM teams WHERE user_id = ? AND mode = ?');
    $stmt->execute([$userId, $mode]);
    $team = $stmt->fetch();
    if (!$team) return null;
    $dstmt = $pdo->prepare('SELECT name, pace, consistency FROM drivers WHERE team_id = ?');
    $dstmt->execute([$team['id']]);
    $team['drivers'] = $dstmt->fetchAll();
    return $team;
}

/** Bepaalt de eerstvolgende te spelen race voor dit team, of null als er (nu) geen is. */
function f1hub_next_race($pdo, $team, $mode) {
    $stmt = $pdo->prepare('SELECT race_key FROM race_results WHERE team_id = ?');
    $stmt->execute([$team['id']]);
    $played = array_column($stmt->fetchAll(), 'race_key');

    if ($mode === 'career') {
        $n = 1;
        while (in_array('career-' . $n, $played, true)) $n++;
        return ['raceKey' => 'career-' . $n, 'raceName' => 'Carrière-race ' . $n];
    }

    $year = (int)date('Y');
    $calendar = f1hub_fetch_season_calendar($year);
    $today = date('Y-m-d');
    foreach ($calendar as $race) {
        if ($race['date'] === null || $race['date'] > $today) continue; // nog niet verreden
        if (in_array($race['raceKey'], $played, true)) continue; // al gespeeld
        return ['raceKey' => $race['raceKey'], 'raceName' => $race['raceName']];
    }
    return null;
}

$mode = (string)($_GET['mode'] ?? $_POST['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$team = f1hub_get_team($pdo, $userId, $mode);
if (!$team) {
    f1hub_json(['ok' => false, 'error' => 'Maak eerst een team aan in deze modus.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $next = f1hub_next_race($pdo, $team, $mode);
    f1hub_json([
        'ok' => true,
        'team' => $team,
        'nextRace' => $next,
        'strategies' => F1HUB_STRATEGIES,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $strategyKey = (string)($_POST['strategy'] ?? '');
    if (!array_key_exists($strategyKey, F1HUB_STRATEGIES)) {
        f1hub_json(['ok' => false, 'error' => 'Ongeldige strategie.'], 422);
    }

    $next = f1hub_next_race($pdo, $team, $mode);
    if (!$next) {
        f1hub_json(['ok' => false, 'error' => 'Geen race beschikbaar om te spelen (seizoensmodus wacht op het volgende GP-weekend).'], 409);
    }

    $result = f1hub_simulate_race((int)$team['car_rating'], $team['drivers'], $strategyKey);

    try {
        $stmt = $pdo->prepare('INSERT INTO race_results (team_id, mode, race_key, strategy, finish_position, points) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$team['id'], $mode, $next['raceKey'], $strategyKey, $result['position'], $result['points']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            f1hub_json(['ok' => false, 'error' => 'Deze race is al gespeeld.'], 409);
        }
        f1hub_json(['ok' => false, 'error' => 'Opslaan van de uitslag is mislukt.'], 500);
    }

    f1hub_json([
        'ok' => true,
        'raceName' => $next['raceName'],
        'position' => $result['position'],
        'points' => $result['points'],
        'grid' => $result['grid'],
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
