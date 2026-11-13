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
    if (isset($_GET['history'])) {
        $stmt = $pdo->prepare(
            'SELECT h.challenger_strategy, h.challenger_won, h.created_at, t.name AS opponent_name, u.username AS opponent_username
             FROM headtohead_matches h
             JOIN teams t ON t.id = h.opponent_team_id
             JOIN users u ON u.id = t.user_id
             WHERE h.challenger_team_id = ?
             ORDER BY h.created_at DESC LIMIT 20'
        );
        $stmt->execute([$team['id']]);
        f1hub_json(['ok' => true, 'history' => $stmt->fetchAll()]);
    }

    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.color_hex, t.car_rating, u.username,
                COALESCE(SUM(rr.points), 0) AS total_points
         FROM teams t
         JOIN users u ON u.id = t.user_id
         LEFT JOIN race_results rr ON rr.team_id = t.id
         WHERE t.mode = ? AND t.id != ?
         GROUP BY t.id, t.name, t.color_hex, t.car_rating, u.username
         ORDER BY total_points DESC, t.name ASC'
    );
    $stmt->execute([$mode, $team['id']]);
    $opponents = array_map(fn($r) => [
        'teamId' => (int)$r['id'],
        'teamName' => $r['name'],
        'colorHex' => $r['color_hex'],
        'carRating' => (int)$r['car_rating'],
        'username' => $r['username'],
        'totalPoints' => (int)$r['total_points'],
    ], $stmt->fetchAll());

    f1hub_json([
        'ok' => true,
        'team' => $team,
        'opponents' => $opponents,
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

    $opponentTeamId = (int)($_POST['opponent_team_id'] ?? 0);
    if ($opponentTeamId === (int)$team['id']) {
        f1hub_json(['ok' => false, 'error' => 'Je kunt jezelf niet uitdagen.'], 422);
    }
    $opponent = f1hub_get_team_by_id($pdo, $opponentTeamId);
    if (!$opponent) {
        f1hub_json(['ok' => false, 'error' => 'Onbekend tegenstander-team.'], 422);
    }
    $stmt = $pdo->prepare('SELECT mode FROM teams WHERE id = ?');
    $stmt->execute([$opponentTeamId]);
    if ($stmt->fetchColumn() !== $mode) {
        f1hub_json(['ok' => false, 'error' => 'Dit team speelt niet in dezelfde modus.'], 422);
    }
    if (count($opponent['drivers']) !== 2) {
        f1hub_json(['ok' => false, 'error' => 'Dit team heeft geen volledige line-up.'], 422);
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

    $challengerStrategy = f1hub_custom_strategy(array_values($compounds), f1hub_stop_penalty_reduction($team['hq_pitcrew_level']), $paceMode, $ersMode);
    $opponentStrategy = f1hub_custom_strategy(f1hub_random_compounds(), f1hub_stop_penalty_reduction($opponent['hq_pitcrew_level']), f1hub_random_pace_mode(), f1hub_random_ers_mode());

    $challengerRating = f1hub_effective_car_rating($team);
    $challengerRiskReduction = max(0, (int)$team['aero_level'] - 1);
    $opponentRating = f1hub_effective_car_rating($opponent);
    $opponentRiskReduction = max(0, (int)$opponent['aero_level'] - 1);

    $result = f1hub_simulate_duel(
        $challengerRating, $team['drivers'], $challengerStrategy, $challengerRiskReduction,
        $opponentRating, $opponent['drivers'], $opponentStrategy, $opponentRiskReduction
    );

    $strategyLabel = implode('-', $challengerStrategy['tyres']);
    $stmt = $pdo->prepare('INSERT INTO headtohead_matches (mode, challenger_team_id, opponent_team_id, challenger_strategy, challenger_won) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$mode, $team['id'], $opponentTeamId, $strategyLabel, $result['challengerWon'] ? 1 : 0]);

    $bonus = $result['challengerWon'] ? 5 : 0;
    if ($bonus > 0) {
        $stmt = $pdo->prepare('UPDATE teams SET budget = budget + ? WHERE id = ?');
        $stmt->execute([$bonus, $team['id']]);
    }

    f1hub_json([
        'ok' => true,
        'opponentName' => $opponent['name'],
        'challengerScore' => round($result['challengerScore'], 1),
        'opponentScore' => round($result['opponentScore'], 1),
        'challengerWon' => $result['challengerWon'],
        'bonus' => $bonus,
        'budget' => (int)$team['budget'] + $bonus,
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
