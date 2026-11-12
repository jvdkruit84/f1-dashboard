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

$stmt = $pdo->prepare('SELECT COUNT(*) AS races_played, COALESCE(SUM(points), 0) AS total_points FROM race_results WHERE team_id = ?');
$stmt->execute([$team['id']]);
$stats = $stmt->fetch();

f1hub_json([
    'ok' => true,
    'team' => $team,
    'nextRace' => $next,
    'racesPlayed' => (int)$stats['races_played'],
    'totalPoints' => (int)$stats['total_points'],
    'engineMaxLevel' => F1HUB_ENGINE_MAX_LEVEL,
    'nextEngineCost' => f1hub_next_engine_cost((int)$team['engine_level']),
]);
