<?php
// Eén aggregerend endpoint voor het "Mijn Team"-dashboard, zodat de pagina niet
// meerdere losse calls (team/race/engine) hoeft te doen om zichzelf te vullen.
require __DIR__ . '/../db.php';
require __DIR__ . '/../team_helpers.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

$mode = (string)($_GET['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$team = f1hub_get_team_full($pdo, $userId, $mode);
if (!$team) {
    f1hub_json(['ok' => true, 'team' => null]);
}

$next = f1hub_next_race($pdo, $team, $mode);
$flavorCircuit = $next ? f1hub_resolve_flavor_circuit($mode, $next) : null;
$circuitWinners = $flavorCircuit ? f1hub_fetch_circuit_winners($flavorCircuit['circuitId']) : [];

$stmt = $pdo->prepare('SELECT COUNT(*) AS races_played, COALESCE(SUM(points), 0) AS total_points FROM race_results WHERE team_id = ?');
$stmt->execute([$team['id']]);
$stats = $stmt->fetch();

f1hub_json([
    'ok' => true,
    'team' => $team,
    'nextRace' => $next,
    'flavorCircuit' => $flavorCircuit,
    'circuitWinners' => $circuitWinners,
    'racesPlayed' => (int)$stats['races_played'],
    'totalPoints' => (int)$stats['total_points'],
    'partMaxLevel' => F1HUB_PART_MAX_LEVEL,
    'nextEngineCost' => f1hub_discounted_part_cost((int)$team['engine_level'], f1hub_part_discount_percent((int)$team['hq_cfd_level'])),
    'nextAeroCost' => f1hub_discounted_part_cost((int)$team['aero_level'], f1hub_part_discount_percent((int)$team['hq_windtunnel_level'])),
    'nextChassisCost' => f1hub_next_part_cost((int)$team['chassis_level']),
    'hqTotalLevel' => (int)$team['hq_windtunnel_level'] + (int)$team['hq_cfd_level'] + (int)$team['hq_simulator_level'] + (int)$team['hq_pitcrew_level'],
]);
