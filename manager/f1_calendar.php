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
