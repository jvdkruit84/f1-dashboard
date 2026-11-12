<?php
// Vaste strategie-opties voor v0.1 (geen vrije pitstop-editor, dat komt in een latere minor-versie).
const F1HUB_STRATEGIES = [
    'onestop_safe'       => ['label' => 'One-stop (medium → hard)',  'bonus' => 0, 'risk' => 4, 'tyres' => ['medium', 'hard']],
    'onestop_aggressive' => ['label' => 'One-stop (soft → hard)',    'bonus' => 3, 'risk' => 7, 'tyres' => ['soft', 'hard']],
    'twostop'            => ['label' => 'Two-stop (soft → soft → hard)', 'bonus' => 1, 'risk' => 5, 'tyres' => ['soft', 'soft', 'hard']],
];

const F1HUB_POINTS_TABLE = [25, 18, 15, 12, 10, 8, 6, 4, 2, 1];

function f1hub_random_float($min, $max) {
    return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

function f1hub_team_score($carRating, $drivers, $strategyKey, $riskReduction = 0) {
    $strategy = F1HUB_STRATEGIES[$strategyKey];
    $avgPace = array_sum(array_column($drivers, 'pace')) / count($drivers);
    $avgConsistency = array_sum(array_column($drivers, 'consistency')) / count($drivers);
    $risk = max(1, $strategy['risk'] - $riskReduction);
    $noise = f1hub_random_float(-$risk, $risk);
    return $carRating * 0.5 + $avgPace * 0.35 + $avgConsistency * 0.15 + $strategy['bonus'] + $noise;
}

/**
 * Simuleert één race: speler + 19 AI-teams, retourneert de volledige uitslag
 * (gesorteerd op score) en de positie/punten van de speler. $riskReduction
 * komt van de aero-upgrade (v0.7) en geldt alleen voor de speler — AI-teams
 * hebben geen onderdelen om te upgraden.
 */
function f1hub_simulate_race($playerCarRating, $playerDrivers, $playerStrategyKey, $riskReduction = 0) {
    $strategyKeys = array_keys(F1HUB_STRATEGIES);
    $entries = [];

    $entries[] = [
        'isPlayer' => true,
        'label' => 'Jouw team',
        'score' => f1hub_team_score($playerCarRating, $playerDrivers, $playerStrategyKey, $riskReduction),
    ];

    for ($i = 0; $i < 19; $i++) {
        $aiCarRating = mt_rand(50, 95);
        $aiDrivers = [
            ['pace' => mt_rand(50, 95), 'consistency' => mt_rand(50, 95)],
            ['pace' => mt_rand(50, 95), 'consistency' => mt_rand(50, 95)],
        ];
        $aiStrategy = $strategyKeys[array_rand($strategyKeys)];
        $entries[] = [
            'isPlayer' => false,
            'label' => 'AI-team ' . ($i + 1),
            'score' => f1hub_team_score($aiCarRating, $aiDrivers, $aiStrategy),
        ];
    }

    usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);

    $playerPosition = null;
    foreach ($entries as $idx => $e) {
        if ($e['isPlayer']) { $playerPosition = $idx + 1; break; }
    }
    $points = F1HUB_POINTS_TABLE[$playerPosition - 1] ?? 0;

    return [
        'position' => $playerPosition,
        'points' => $points,
        'grid' => array_map(fn($e, $i) => ['position' => $i + 1, 'label' => $e['label'], 'isPlayer' => $e['isPlayer']], $entries, array_keys($entries)),
    ];
}

/**
 * Race Replay (v0.2): herspeelt een al verreden race. $classification is de
 * echte einduitslag (per coureur, gesorteerd op positie). Alleen de
 * best-geklasseerde coureur van het gekozen team krijgt de strategiebonus/
 * -ruis bovenop zijn echte baseline-score; de rest van het veld staat vast
 * op het echte resultaat — zo kun je het echte resultaat proberen te
 * verslaan, zoals de "Race Moments" uit de echte F1 Manager-games.
 */
function f1hub_simulate_replay($classification, $playerConstructorName, $strategyKey) {
    $strategy = F1HUB_STRATEGIES[$strategyKey];
    $total = count($classification);

    $entries = [];
    $playerIdx = null;
    $realPosition = null;
    foreach ($classification as $i => $c) {
        $entries[] = [
            'label' => $c['driverName'] . ' (' . $c['constructorName'] . ')',
            'isPlayer' => false,
            'score' => $total - $c['position'] + 1,
        ];
        if ($playerIdx === null && $c['constructorName'] === $playerConstructorName) {
            $playerIdx = $i;
            $realPosition = (int)$c['position'];
        }
    }
    if ($playerIdx === null) return null;

    $entries[$playerIdx]['isPlayer'] = true;
    $entries[$playerIdx]['score'] += $strategy['bonus'] + f1hub_random_float(-$strategy['risk'], $strategy['risk']);

    usort($entries, fn($a, $b) => $b['score'] <=> $a['score']);

    $simPosition = null;
    foreach ($entries as $idx => $e) {
        if ($e['isPlayer']) { $simPosition = $idx + 1; break; }
    }

    return [
        'realPosition' => $realPosition,
        'simPosition' => $simPosition,
        'grid' => array_map(fn($e, $i) => ['position' => $i + 1, 'label' => $e['label'], 'isPlayer' => $e['isPlayer']], $entries, array_keys($entries)),
    ];
}
