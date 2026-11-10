<?php
// Vaste strategie-opties voor v0.1 (geen vrije pitstop-editor, dat komt in een latere minor-versie).
const F1HUB_STRATEGIES = [
    'onestop_safe'       => ['label' => 'One-stop (medium → hard)',  'bonus' => 0, 'risk' => 4],
    'onestop_aggressive' => ['label' => 'One-stop (soft → hard)',    'bonus' => 3, 'risk' => 7],
    'twostop'            => ['label' => 'Two-stop (soft → soft → hard)', 'bonus' => 1, 'risk' => 5],
];

const F1HUB_POINTS_TABLE = [25, 18, 15, 12, 10, 8, 6, 4, 2, 1];

function f1hub_random_float($min, $max) {
    return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

function f1hub_team_score($carRating, $drivers, $strategyKey) {
    $strategy = F1HUB_STRATEGIES[$strategyKey];
    $avgPace = array_sum(array_column($drivers, 'pace')) / count($drivers);
    $avgConsistency = array_sum(array_column($drivers, 'consistency')) / count($drivers);
    $noise = f1hub_random_float(-$strategy['risk'], $strategy['risk']);
    return $carRating * 0.5 + $avgPace * 0.35 + $avgConsistency * 0.15 + $strategy['bonus'] + $noise;
}

/**
 * Simuleert één race: speler + 19 AI-teams, retourneert de volledige uitslag
 * (gesorteerd op score) en de positie/punten van de speler.
 */
function f1hub_simulate_race($playerCarRating, $playerDrivers, $playerStrategyKey) {
    $strategyKeys = array_keys(F1HUB_STRATEGIES);
    $entries = [];

    $entries[] = [
        'isPlayer' => true,
        'label' => 'Jouw team',
        'score' => f1hub_team_score($playerCarRating, $playerDrivers, $playerStrategyKey),
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
