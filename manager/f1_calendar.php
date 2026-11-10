<?php
// Serverside ophalen van de echte kalender (Jolpica/Ergast-mirror), voor seizoensmodus.
// Lichte caching in een tmp-bestand om niet bij elke request de externe API te slaan.
function f1hub_fetch_season_calendar($year) {
    $cacheFile = sys_get_temp_dir() . "/f1hub_calendar_{$year}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $url = "https://api.jolpi.ca/ergast/f1/{$year}.json?limit=100";
    $context = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Formule1Hub\r\n"]]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) return [];

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

    file_put_contents($cacheFile, json_encode($calendar));
    return $calendar;
}
