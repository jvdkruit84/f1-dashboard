<?php
require_once __DIR__ . '/f1_calendar.php';

/** Haalt een team (incl. budget/engine_level/aero_level) + coureurs op voor de ingelogde gebruiker in een modus. */
function f1hub_get_team_full($pdo, $userId, $mode) {
    $stmt = $pdo->prepare('SELECT id, name, color_hex, car_rating, budget, engine_level, aero_level FROM teams WHERE user_id = ? AND mode = ?');
    $stmt->execute([$userId, $mode]);
    $team = $stmt->fetch();
    if (!$team) return null;
    $dstmt = $pdo->prepare('SELECT id, name, pace, consistency FROM drivers WHERE team_id = ?');
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

/** Effectieve car_rating inclusief motorbonus (+4 per niveau boven 1), alleen gebruikt bij het rijden van races. */
function f1hub_effective_car_rating($team) {
    return (int)$team['car_rating'] + ((int)$team['engine_level'] - 1) * 4;
}

// kosten om van niveau N naar N+1 te gaan; index 0 = 1->2, ... index 3 = 4->5. Max niveau 5.
// Gedeeld door alle opwaardeerbare auto-onderdelen (motor, aero, ...) — zelfde curve voor elk.
const F1HUB_PART_UPGRADE_COSTS = [20, 30, 40, 50];
const F1HUB_PART_MAX_LEVEL = 5;

function f1hub_next_part_cost($level) {
    if ($level >= F1HUB_PART_MAX_LEVEL) return null;
    return F1HUB_PART_UPGRADE_COSTS[$level - 1];
}
