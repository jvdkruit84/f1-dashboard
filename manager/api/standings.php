<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../f1_calendar.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

$mode = (string)($_GET['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$stmt = $pdo->prepare('SELECT id, name, color_hex, car_rating FROM teams WHERE user_id = ? AND mode = ?');
$stmt->execute([$userId, $mode]);
$team = $stmt->fetch();
if (!$team) {
    f1hub_json(['ok' => false, 'error' => 'Nog geen team in deze modus.'], 404);
}

$stmt = $pdo->prepare('SELECT race_key, strategy, finish_position, points, created_at FROM race_results WHERE team_id = ? ORDER BY created_at ASC');
$stmt->execute([$team['id']]);
$results = $stmt->fetchAll();

$raceNames = [];
if ($mode === 'season') {
    $year = (int)date('Y');
    foreach (f1hub_fetch_season_calendar($year) as $race) {
        $raceNames[$race['raceKey']] = $race['raceName'];
    }
}

$history = array_map(function ($r) use ($raceNames, $mode) {
    $label = $mode === 'season'
        ? ($raceNames[$r['race_key']] ?? $r['race_key'])
        : 'Carrière-race ' . str_replace('career-', '', $r['race_key']);
    return [
        'raceKey' => $r['race_key'],
        'raceName' => $label,
        'strategy' => $r['strategy'],
        'position' => (int)$r['finish_position'],
        'points' => (int)$r['points'],
    ];
}, $results);

$totalPoints = array_sum(array_column($history, 'points'));

f1hub_json([
    'ok' => true,
    'team' => $team,
    'racesPlayed' => count($history),
    'totalPoints' => $totalPoints,
    'history' => $history,
]);
