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
        'compounds' => F1HUB_TYRE_COMPOUNDS,
        'minStints' => F1HUB_MIN_STINTS,
        'maxStints' => F1HUB_MAX_STINTS,
        'paceModes' => F1HUB_PACE_MODES,
        'ersModes' => F1HUB_ERS_MODES,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (count($team['drivers']) !== 2) {
        f1hub_json(['ok' => false, 'error' => 'Je hebt geen volledig team — vind een vervanger via de transfermarkt.'], 422);
    }

    $compounds = array_filter(explode(',', (string)($_POST['compounds'] ?? '')));
    $count = count($compounds);
    if ($count < F1HUB_MIN_STINTS || $count > F1HUB_MAX_STINTS) {
        f1hub_json(['ok' => false, 'error' => "Kies tussen de " . F1HUB_MIN_STINTS . " en " . F1HUB_MAX_STINTS . " bandenstints."], 422);
    }
    foreach ($compounds as $c) {
        if (!array_key_exists($c, F1HUB_TYRE_COMPOUNDS)) {
            f1hub_json(['ok' => false, 'error' => 'Onbekende bandencompound.'], 422);
        }
    }
    $paceMode = (string)($_POST['pace_mode'] ?? 'balanced');
    if (!array_key_exists($paceMode, F1HUB_PACE_MODES)) {
        f1hub_json(['ok' => false, 'error' => 'Onbekend rij-tempo.'], 422);
    }
    $ersMode = (string)($_POST['ers_mode'] ?? 'neutral');
    if (!array_key_exists($ersMode, F1HUB_ERS_MODES)) {
        f1hub_json(['ok' => false, 'error' => 'Onbekende ERS-modus.'], 422);
    }
    $strategy = f1hub_custom_strategy(array_values($compounds), f1hub_stop_penalty_reduction($team['hq_pitcrew_level']), $paceMode, $ersMode);

    $next = f1hub_next_race($pdo, $team, $mode);
    if (!$next) {
        f1hub_json(['ok' => false, 'error' => 'Geen race beschikbaar om te spelen (seizoensmodus wacht op het volgende GP-weekend).'], 409);
    }

    $effectiveCarRating = f1hub_effective_car_rating($team);
    $riskReduction = max(0, (int)$team['aero_level'] - 1);
    $result = f1hub_simulate_race($effectiveCarRating, $team['drivers'], $strategy, $riskReduction);
    $strategyLabel = implode('-', $strategy['tyres']);

    try {
        $stmt = $pdo->prepare('INSERT INTO race_results (team_id, mode, race_key, strategy, finish_position, points) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$team['id'], $mode, $next['raceKey'], $strategyLabel, $result['position'], $result['points']]);

        $stmt = $pdo->prepare('UPDATE teams SET budget = budget + ? WHERE id = ?');
        $stmt->execute([$result['points'], $team['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            f1hub_json(['ok' => false, 'error' => 'Deze race is al gespeeld.'], 409);
        }
        f1hub_json(['ok' => false, 'error' => 'Opslaan van de uitslag is mislukt.'], 500);
    }

    $stmt = $pdo->prepare('UPDATE drivers SET contract_races_left = contract_races_left - 1 WHERE team_id = ? AND contract_races_left > 0');
    $stmt->execute([$team['id']]);

    $stmt = $pdo->prepare('SELECT id, name FROM drivers WHERE team_id = ? AND contract_races_left <= 0');
    $stmt->execute([$team['id']]);
    $expired = $stmt->fetchAll();
    if ($expired) {
        $stmt = $pdo->prepare('DELETE FROM drivers WHERE team_id = ? AND contract_races_left <= 0');
        $stmt->execute([$team['id']]);
    }

    f1hub_json([
        'ok' => true,
        'raceName' => $next['raceName'],
        'position' => $result['position'],
        'points' => $result['points'],
        'budget' => (int)$team['budget'] + $result['points'],
        'grid' => $result['grid'],
        'expiredDrivers' => array_column($expired, 'name'),
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
