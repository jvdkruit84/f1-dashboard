<?php
const F1HUB_POINTS_TABLE = [25, 18, 15, 12, 10, 8, 6, 4, 2, 1];

// Vrije strategie-editor (v0.8 Race-weekend, v1.0 ook Race Replay): de speler
// bouwt zelf een bandenvolgorde van 2-3 stints uit onderstaande compounds.
const F1HUB_TYRE_COMPOUNDS = [
    'soft'   => ['label' => 'Soft',   'bonus' => 3,   'risk' => 3],
    'medium' => ['label' => 'Medium', 'bonus' => 1,   'risk' => 1.5],
    'hard'   => ['label' => 'Hard',   'bonus' => 0,   'risk' => 0.5],
];
const F1HUB_MIN_STINTS = 2;
const F1HUB_MAX_STINTS = 3;

// Rij-tempo & ERS-modus (v1.2): twee extra, onafhankelijke bonus/risico-hefbomen
// bovenop de bandenstrategie, zelfde mechaniek als F1HUB_TYRE_COMPOUNDS hierboven.
const F1HUB_PACE_MODES = [
    'conserve' => ['label' => 'Banden sparen', 'bonus' => -1, 'risk' => -1.5],
    'balanced' => ['label' => 'Gebalanceerd',  'bonus' => 0,  'risk' => 0],
    'push'     => ['label' => 'Pushen',        'bonus' => 2,  'risk' => 2.5],
];
const F1HUB_ERS_MODES = [
    'conserve' => ['label' => 'Sparen',     'bonus' => -1, 'risk' => -0.5],
    'neutral'  => ['label' => 'Neutraal',   'bonus' => 0,  'risk' => 0],
    'overtake' => ['label' => 'Inhalen',    'bonus' => 2,  'risk' => 1],
    'defend'   => ['label' => 'Verdedigen', 'bonus' => 0,  'risk' => -1],
];

/**
 * Bouwt een strategie-array (bonus/risk/tyres/label) uit een bandenvolgorde.
 * $stopPenaltyReduction (0-0.8, van Pitcrew-faciliteiten, v1.1) verlaagt de
 * risicostraf van een extra pitstop boven de verplichte eerste. $paceMode en
 * $ersMode (v1.2) tellen hun eigen bonus/risico op bovenop de bandenkeuze.
 */
function f1hub_custom_strategy(array $compounds, $stopPenaltyReduction = 0, $paceMode = 'balanced', $ersMode = 'neutral') {
    $bonus = array_sum(array_map(fn($c) => F1HUB_TYRE_COMPOUNDS[$c]['bonus'], $compounds))
        + F1HUB_PACE_MODES[$paceMode]['bonus'] + F1HUB_ERS_MODES[$ersMode]['bonus'];
    $risk = array_sum(array_map(fn($c) => F1HUB_TYRE_COMPOUNDS[$c]['risk'], $compounds))
        + max(0, count($compounds) - 2) * (1 - $stopPenaltyReduction)
        + F1HUB_PACE_MODES[$paceMode]['risk'] + F1HUB_ERS_MODES[$ersMode]['risk'];
    return [
        'bonus' => $bonus,
        'risk' => $risk,
        'tyres' => $compounds,
        'label' => implode(' → ', array_map(fn($c) => F1HUB_TYRE_COMPOUNDS[$c]['label'], $compounds)),
    ];
}

function f1hub_random_compounds() {
    $keys = array_keys(F1HUB_TYRE_COMPOUNDS);
    $n = mt_rand(F1HUB_MIN_STINTS, F1HUB_MAX_STINTS);
    $compounds = [];
    for ($i = 0; $i < $n; $i++) $compounds[] = $keys[array_rand($keys)];
    return $compounds;
}

function f1hub_random_pace_mode() {
    $keys = array_keys(F1HUB_PACE_MODES);
    return $keys[array_rand($keys)];
}

function f1hub_random_ers_mode() {
    $keys = array_keys(F1HUB_ERS_MODES);
    return $keys[array_rand($keys)];
}

function f1hub_random_float($min, $max) {
    return $min + mt_rand() / mt_getrandmax() * ($max - $min);
}

function f1hub_team_score($carRating, $drivers, $strategy, $riskReduction = 0) {
    $avgPace = array_sum(array_column($drivers, 'pace')) / count($drivers);
    $avgConsistency = array_sum(array_column($drivers, 'consistency')) / count($drivers);
    $risk = max(1, $strategy['risk'] - $riskReduction);
    $noise = f1hub_random_float(-$risk, $risk);
    return $carRating * 0.5 + $avgPace * 0.35 + $avgConsistency * 0.15 + $strategy['bonus'] + $noise;
}

/**
 * Head-to-head (v1.4): vergelijkt twee teams rechtstreeks met elkaar, zelfde
 * scoreformule als f1hub_team_score() (geen nieuw model). De tegenstander
 * speelt geen actieve rol — hun strategie wordt willekeurig bepaald door de
 * aanroeper (zie manager/api/headtohead.php), net als de AI-tegenstanders in
 * f1hub_simulate_race().
 */
function f1hub_simulate_duel($challengerRating, $challengerDrivers, $challengerStrategy, $challengerRiskReduction, $opponentRating, $opponentDrivers, $opponentStrategy, $opponentRiskReduction) {
    $challengerScore = f1hub_team_score($challengerRating, $challengerDrivers, $challengerStrategy, $challengerRiskReduction);
    $opponentScore = f1hub_team_score($opponentRating, $opponentDrivers, $opponentStrategy, $opponentRiskReduction);
    return [
        'challengerScore' => $challengerScore,
        'opponentScore' => $opponentScore,
        'challengerWon' => $challengerScore > $opponentScore,
    ];
}

// Namen van de echte 2026 F1-constructeurs (zelfde "mooie" naam als elders op de site,
// zie F1HUB_TEAM_NAME_ALIASES in real_teams.php), gebruikt als label voor de AI-tegenstanders
// i.p.v. "AI-team N" — statisch hardgecodeerd zodat het rijden van een race nooit van een
// live API-call afhangt (voorkomt de Jolpica-rate-limit-problemen die elders al zijn geraakt).
const F1HUB_AI_TEAM_NAMES = [
    'Mercedes', 'Ferrari', 'Red Bull Racing', 'McLaren', 'Racing Bulls',
    'Alpine', 'Haas F1 Team', 'Audi', 'Williams', 'Aston Martin', 'Cadillac',
];

/**
 * Simuleert één race: speler + 19 AI-teams, retourneert de volledige uitslag
 * (gesorteerd op score) en de positie/punten van de speler. $playerStrategy is
 * een array (zie f1hub_custom_strategy()), geen preset-sleutel meer sinds de
 * vrije strategie-editor (v0.8). $riskReduction komt van de aero-upgrade
 * (v0.7) en geldt alleen voor de speler — AI-teams hebben geen onderdelen om
 * te upgraden, maar bouwen wel dezelfde soort willekeurige bandenstrategie.
 * Elk AI-team krijgt de naam van een echt 2026-team (F1HUB_AI_TEAM_NAMES),
 * cyclisch verdeeld over de 19 posities — net als op een echte grid komt een
 * teamnaam daardoor normaal gesproken twee keer voor (twee auto's per team).
 */
function f1hub_simulate_race($playerCarRating, $playerDrivers, $playerStrategy, $riskReduction = 0) {
    $entries = [];

    $entries[] = [
        'isPlayer' => true,
        'label' => 'Jouw team',
        'score' => f1hub_team_score($playerCarRating, $playerDrivers, $playerStrategy, $riskReduction),
    ];

    $aiTeamCount = count(F1HUB_AI_TEAM_NAMES);
    for ($i = 0; $i < 19; $i++) {
        $aiCarRating = mt_rand(50, 95);
        $aiDrivers = [
            ['pace' => mt_rand(50, 95), 'consistency' => mt_rand(50, 95)],
            ['pace' => mt_rand(50, 95), 'consistency' => mt_rand(50, 95)],
        ];
        $aiStrategy = f1hub_custom_strategy(f1hub_random_compounds(), 0, f1hub_random_pace_mode(), f1hub_random_ers_mode());
        $entries[] = [
            'isPlayer' => false,
            'label' => F1HUB_AI_TEAM_NAMES[$i % $aiTeamCount],
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
 * Race Replay (v0.2, vrije strategie-editor sinds v1.0): herspeelt een al
 * verreden race. $classification is de echte einduitslag (per coureur,
 * gesorteerd op positie). Alleen de best-geklasseerde coureur van het
 * gekozen team krijgt de strategiebonus/-ruis bovenop zijn echte
 * baseline-score; de rest van het veld staat vast op het echte resultaat —
 * zo kun je het echte resultaat proberen te verslaan, zoals de
 * "Race Moments" uit de echte F1 Manager-games. $strategy is een
 * samengesteld array (zie f1hub_custom_strategy()), geen preset-sleutel meer.
 */
function f1hub_simulate_replay($classification, $playerConstructorName, $strategy) {
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
