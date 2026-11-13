<?php
require_once __DIR__ . '/f1_calendar.php';

/** Haalt een team (incl. budget/engine_level/aero_level/hq_*_level) + coureurs op voor de ingelogde gebruiker in een modus. */
function f1hub_get_team_full($pdo, $userId, $mode) {
    $stmt = $pdo->prepare('SELECT id, name, color_hex, car_rating, budget, engine_level, aero_level, hq_windtunnel_level, hq_cfd_level, hq_simulator_level, hq_pitcrew_level FROM teams WHERE user_id = ? AND mode = ?');
    $stmt->execute([$userId, $mode]);
    $team = $stmt->fetch();
    if (!$team) return null;
    $dstmt = $pdo->prepare('SELECT id, name, pace, consistency, contract_races_left FROM drivers WHERE team_id = ?');
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

/** Kortingspercentage (0-20) dat Windtunnel/CFD-niveau geeft op Aero/Motor-upgradekosten. */
function f1hub_part_discount_percent($level) {
    return ($level - 1) * 5;
}

/** Volgende upgradekosten voor Motor/Aero, verlaagd met het Windtunnel/CFD-kortingspercentage. */
function f1hub_discounted_part_cost($level, $discountPercent) {
    $cost = f1hub_next_part_cost($level);
    if ($cost === null) return null;
    return (int)round($cost * (1 - $discountPercent / 100));
}

// Vaste kosten per coureur-training (Racesimulator, v1.1) — zelfde ongeacht niveau,
// alleen de winst per training schaalt met hq_simulator_level.
const F1HUB_TRAINING_COST = 5;
const F1HUB_TRAINING_STAT_CAP = 99;

/** Fractie (0-0.8) waarmee Pitcrew-niveau de risicostraf van een extra pitstop vermindert. */
function f1hub_stop_penalty_reduction($pitcrewLevel) {
    return ($pitcrewLevel - 1) * 0.2;
}

// Contractverloop (v1.3): vaste looptijd (in races) en vaste verlengingsprijs voor elke coureur.
const F1HUB_CONTRACT_LENGTH = 8;
const F1HUB_CONTRACT_RENEWAL_COST = 10;
