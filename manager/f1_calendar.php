<?php
require_once __DIR__ . '/http.php';

// Serverside ophalen van de echte kalender (Jolpica/Ergast-mirror), voor seizoensmodus.
// Lichte caching in een tmp-bestand om niet bij elke request de externe API te slaan.
function f1hub_fetch_season_calendar($year) {
    $cacheFile = sys_get_temp_dir() . "/f1hub_calendar_{$year}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $raw = f1hub_http_get("https://api.jolpi.ca/ergast/f1/{$year}.json?limit=100");
    if ($raw === null) return [];

    $data = json_decode($raw, true);
    $races = $data['MRData']['RaceTable']['Races'] ?? [];

    $calendar = [];
    foreach ($races as $r) {
        $calendar[] = [
            'round' => (int)$r['round'],
            'raceKey' => $year . '-r' . $r['round'],
            'raceName' => $r['raceName'] ?? ('Ronde ' . $r['round']),
            'date' => $r['date'] ?? null,
            'circuitId' => $r['Circuit']['circuitId'] ?? null,
            'circuitName' => $r['Circuit']['circuitName'] ?? null,
        ];
    }
    usort($calendar, fn($a, $b) => $a['round'] <=> $b['round']);

    // alleen een echt (niet-leeg) resultaat cachen — anders blijft een tijdelijke
    // API-hik (timeout/rate-limit) tot een uur lang als "geen races" hangen
    if ($calendar) file_put_contents($cacheFile, json_encode($calendar));
    return $calendar;
}

/** Echte einduitslag van een al verreden race (voor Race Replay, v0.2). */
function f1hub_fetch_race_classification($year, $round) {
    $cacheFile = sys_get_temp_dir() . "/f1hub_results_{$year}_{$round}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $raw = f1hub_http_get("https://api.jolpi.ca/ergast/f1/{$year}/{$round}/results.json");
    if ($raw === null) return null;

    $data = json_decode($raw, true);
    $race = $data['MRData']['RaceTable']['Races'][0] ?? null;
    if (!$race) return null;

    $classification = [];
    foreach ($race['Results'] as $res) {
        $classification[] = [
            'position' => (int)$res['position'],
            'driverName' => $res['Driver']['givenName'] . ' ' . $res['Driver']['familyName'],
            'constructorName' => $res['Constructor']['name'],
        ];
    }
    usort($classification, fn($a, $b) => $a['position'] <=> $b['position']);

    $result = ['raceName' => $race['raceName'], 'classification' => $classification];
    if ($classification) file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/**
 * Winnaars van de laatste 5 edities van een circuit (Race-weekend "Circuitlayout"-kaart).
 * Historische uitslagen veranderen nooit, vandaar een lange cache (7 dagen).
 */
function f1hub_fetch_circuit_winners($circuitId) {
    $cacheFile = sys_get_temp_dir() . "/f1hub_circuit_winners_{$circuitId}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $raw = f1hub_http_get("https://api.jolpi.ca/ergast/f1/circuits/{$circuitId}/results/1.json?limit=100");
    if ($raw === null) return [];

    $data = json_decode($raw, true);
    $races = $data['MRData']['RaceTable']['Races'] ?? [];

    $winners = [];
    foreach ($races as $r) {
        $res = $r['Results'][0] ?? null;
        if (!$res) continue;
        $winners[] = [
            'year' => (int)$r['season'],
            'driverName' => $res['Driver']['givenName'] . ' ' . $res['Driver']['familyName'],
            'constructorName' => $res['Constructor']['name'],
        ];
    }
    // oplopend gesorteerd door Jolpica — laatste 5, nieuwste eerst
    $lastFive = array_reverse(array_slice($winners, -5));

    if ($winners) file_put_contents($cacheFile, json_encode($lastFive));
    return $lastFive;
}

/**
 * Bepaalt welk écht circuit bij de eerstvolgende race hoort, voor zowel seizoens- als
 * carrièremodus op dezelfde manier (Race-weekend "Circuitlayout"-kaart, v1.7). Carrièremodus
 * heeft geen eigen circuit (races zijn fictief) — krijgt cyclisch een entry uit het
 * al-verreden 2024-seizoen toegewezen, puur ter sfeer.
 */
function f1hub_resolve_flavor_circuit($mode, $nextRace) {
    if ($mode === 'season') {
        $year = (int)date('Y');
        $calendar = f1hub_fetch_season_calendar($year);
        foreach ($calendar as $race) {
            if ($race['raceKey'] === $nextRace['raceKey']) return $race;
        }
        return null;
    }

    $calendar = f1hub_fetch_season_calendar(2024);
    if (!$calendar) return null;
    $n = (int)substr($nextRace['raceKey'] ?? 'career-1', strlen('career-'));
    if ($n < 1) $n = 1;
    return $calendar[($n - 1) % count($calendar)];
}
