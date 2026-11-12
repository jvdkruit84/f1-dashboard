<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../team_helpers.php';
require __DIR__ . '/../simulate.php';

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

    $effectiveCarRating = f1hub_effective_car_rating($team);
    $riskReduction = max(0, (int)$team['aero_level'] - 1);
    $result = f1hub_simulate_race($effectiveCarRating, $team['drivers'], $strategyKey, $riskReduction);

    try {
        $stmt = $pdo->prepare('INSERT INTO race_results (team_id, mode, race_key, strategy, finish_position, points) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$team['id'], $mode, $next['raceKey'], $strategyKey, $result['position'], $result['points']]);

        $stmt = $pdo->prepare('UPDATE teams SET budget = budget + ? WHERE id = ?');
        $stmt->execute([$result['points'], $team['id']]);
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
        'budget' => (int)$team['budget'] + $result['points'],
        'grid' => $result['grid'],
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
