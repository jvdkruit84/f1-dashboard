<?php
// Publieke stand (v0.3) — geen login vereist. Rangschikt alle teams in een
// modus op totaal aantal punten uit race_results.
require __DIR__ . '/../db.php';

$pdo = f1hub_db();

$mode = (string)($_GET['mode'] ?? '');
if (!in_array($mode, ['season', 'career'], true)) {
    f1hub_json(['ok' => false, 'error' => 'Ongeldige modus.'], 422);
}

$stmt = $pdo->prepare(
    'SELECT u.username, t.name, t.color_hex, t.car_rating,
            COUNT(rr.id) AS races_played,
            COALESCE(SUM(rr.points), 0) AS total_points
     FROM teams t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN race_results rr ON rr.team_id = t.id
     WHERE t.mode = ?
     GROUP BY t.id, u.username, t.name, t.color_hex, t.car_rating
     ORDER BY total_points DESC, races_played ASC, t.name ASC'
);
$stmt->execute([$mode]);
$rows = $stmt->fetchAll();

$leaderboard = array_map(fn($r) => [
    'username' => $r['username'],
    'teamName' => $r['name'],
    'colorHex' => $r['color_hex'],
    'carRating' => (int)$r['car_rating'],
    'racesPlayed' => (int)$r['races_played'],
    'totalPoints' => (int)$r['total_points'],
], $rows);

f1hub_json(['ok' => true, 'mode' => $mode, 'leaderboard' => $leaderboard]);
