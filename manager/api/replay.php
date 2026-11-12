<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../f1_calendar.php';
require __DIR__ . '/../simulate.php';

$userId = f1hub_require_login();
$pdo = f1hub_db();

const F1HUB_REPLAY_MIN_YEAR = 2018;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = (int)($_GET['year'] ?? 0);
    $round = (int)($_GET['round'] ?? 0);

    if (isset($_GET['history'])) {
        $stmt = $pdo->prepare('SELECT race_name, constructor_name, strategy, real_position, sim_position, created_at FROM replay_attempts WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
        $stmt->execute([$userId]);
        f1hub_json(['ok' => true, 'history' => $stmt->fetchAll()]);
    }

    if (!$year) {
        $currentYear = (int)date('Y');
        $years = range($currentYear, F1HUB_REPLAY_MIN_YEAR);
        f1hub_json(['ok' => true, 'years' => $years]);
    }

    if ($year && !$round) {
        $calendar = f1hub_fetch_season_calendar($year);
        $today = date('Y-m-d');
        // alleen al verreden races zijn een "replay"-uitdaging
        $played = array_values(array_filter($calendar, fn($r) => $r['date'] !== null && $r['date'] <= $today));
        f1hub_json(['ok' => true, 'races' => $played]);
    }

    $data = f1hub_fetch_race_classification($year, $round);
    if (!$data) {
        f1hub_json(['ok' => false, 'error' => 'Kon de uitslag van deze race niet ophalen.'], 404);
    }
    // unieke teamnamen uit de echte uitslag, voor de team-kiezer
    $teams = [];
    foreach ($data['classification'] as $c) {
        if (!in_array($c['constructorName'], $teams, true)) $teams[] = $c['constructorName'];
    }
    f1hub_json([
        'ok' => true,
        'raceName' => $data['raceName'],
        'teams' => $teams,
        'compounds' => F1HUB_TYRE_COMPOUNDS,
        'minStints' => F1HUB_MIN_STINTS,
        'maxStints' => F1HUB_MAX_STINTS,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year = (int)($_POST['year'] ?? 0);
    $round = (int)($_POST['round'] ?? 0);
    $constructorName = (string)($_POST['constructor_name'] ?? '');
    $compounds = array_filter(explode(',', (string)($_POST['compounds'] ?? '')));
    $count = count($compounds);

    if (!$year || !$round || $constructorName === '') {
        f1hub_json(['ok' => false, 'error' => 'Ongeldige aanvraag.'], 422);
    }
    if ($count < F1HUB_MIN_STINTS || $count > F1HUB_MAX_STINTS) {
        f1hub_json(['ok' => false, 'error' => "Kies tussen de " . F1HUB_MIN_STINTS . " en " . F1HUB_MAX_STINTS . " bandenstints."], 422);
    }
    foreach ($compounds as $c) {
        if (!array_key_exists($c, F1HUB_TYRE_COMPOUNDS)) {
            f1hub_json(['ok' => false, 'error' => 'Onbekende bandencompound.'], 422);
        }
    }
    $strategy = f1hub_custom_strategy(array_values($compounds));

    $data = f1hub_fetch_race_classification($year, $round);
    if (!$data) {
        f1hub_json(['ok' => false, 'error' => 'Kon de uitslag van deze race niet ophalen.'], 404);
    }

    $result = f1hub_simulate_replay($data['classification'], $constructorName, $strategy);
    if (!$result) {
        f1hub_json(['ok' => false, 'error' => 'Dit team reed deze race niet mee.'], 422);
    }

    $strategyLabel = implode('-', $strategy['tyres']);
    $stmt = $pdo->prepare('INSERT INTO replay_attempts (user_id, race_key, race_name, constructor_name, strategy, real_position, sim_position) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, "{$year}-r{$round}", $data['raceName'], $constructorName, $strategyLabel, $result['realPosition'], $result['simPosition']]);

    f1hub_json([
        'ok' => true,
        'raceName' => $data['raceName'],
        'realPosition' => $result['realPosition'],
        'simPosition' => $result['simPosition'],
        'grid' => $result['grid'],
    ]);
}

f1hub_json(['ok' => false, 'error' => 'Methode niet ondersteund.'], 405);
