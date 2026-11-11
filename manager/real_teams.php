<?php
require_once __DIR__ . '/http.php';

// Echte 2026-teams/coureurs voor de "bestaand team overnemen"-flow (v0.2).
// Rating is afgeleid van de actuele WK-stand (Jolpica), niet een officiële skill-score.

// Jolpica en OpenF1 gebruiken soms andere teamnamen voor hetzelfde team.
// Klein, expliciet alias-bestand i.p.v. fuzzy matching (betrouwbaarder bij 11 teams).
const F1HUB_TEAM_NAME_ALIASES = [
    'Red Bull' => 'Red Bull Racing',
    'RB F1 Team' => 'Racing Bulls',
    'Alpine F1 Team' => 'Alpine',
    'Cadillac F1 Team' => 'Cadillac',
    'Haas F1 Team' => 'Haas F1 Team',
];

function f1hub_openf1_team_colours() {
    $cacheFile = sys_get_temp_dir() . '/f1hub_team_colours.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $raw = f1hub_http_get('https://api.openf1.org/v1/drivers?session_key=latest');
    $colours = [];
    if ($raw !== null) {
        $data = json_decode($raw, true) ?: [];
        foreach ($data as $d) {
            if (!empty($d['team_name']) && !empty($d['team_colour'])) {
                $colours[$d['team_name']] = $d['team_colour'];
            }
        }
    }
    if ($colours) file_put_contents($cacheFile, json_encode($colours));
    return $colours;
}

function f1hub_rank_to_rating($rank, $total, $min = 55, $max = 92) {
    if ($total <= 1) return $max;
    $t = ($rank - 1) / ($total - 1);
    return (int)round($max - $t * ($max - $min));
}

/** Stabiele kleine jitter op basis van de naam, zodat herhaalde aanroepen consistent blijven. */
function f1hub_name_jitter($name, $spread = 6) {
    $hash = crc32($name);
    return ($hash % ($spread * 2 + 1)) - $spread;
}

function f1hub_fetch_real_teams($year) {
    $cacheFile = sys_get_temp_dir() . "/f1hub_real_teams_{$year}.json";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $consRaw = f1hub_http_get("https://api.jolpi.ca/ergast/f1/{$year}/constructorstandings.json");
    $driversRaw = f1hub_http_get("https://api.jolpi.ca/ergast/f1/{$year}/driverstandings.json?limit=40");
    if ($consRaw === null || $driversRaw === null) return [];

    $consStandings = (json_decode($consRaw, true))['MRData']['StandingsTable']['StandingsLists'][0]['ConstructorStandings'] ?? [];
    $driverStandings = (json_decode($driversRaw, true))['MRData']['StandingsTable']['StandingsLists'][0]['DriverStandings'] ?? [];
    if (!$consStandings) return [];

    $colours = f1hub_openf1_team_colours();
    $totalTeams = count($consStandings);
    $totalDrivers = count($driverStandings);

    // coureurs groeperen op hun meest recente constructeur (laatste in de lijst bij een mid-season switch)
    $driversByTeam = [];
    foreach ($driverStandings as $i => $ds) {
        $constructors = $ds['Constructors'] ?? [];
        if (!$constructors) continue;
        $teamName = end($constructors)['name'];
        if (!isset($driversByTeam[$teamName])) $driversByTeam[$teamName] = [];
        if (count($driversByTeam[$teamName]) >= 2) continue; // max 2 coureurs per team
        $pace = f1hub_rank_to_rating($i + 1, $totalDrivers);
        $driversByTeam[$teamName][] = [
            'name' => $ds['Driver']['givenName'] . ' ' . $ds['Driver']['familyName'],
            'pace' => $pace,
            'consistency' => max(40, min(99, $pace + f1hub_name_jitter($ds['Driver']['familyName']))),
        ];
    }

    $teams = [];
    foreach ($consStandings as $i => $cs) {
        $name = $cs['Constructor']['name'];
        $openf1Name = F1HUB_TEAM_NAME_ALIASES[$name] ?? $name;
        $teams[] = [
            'constructorId' => $cs['Constructor']['constructorId'],
            'name' => $name,
            'colorHex' => $colours[$openf1Name] ?? 'e10600',
            'carRating' => f1hub_rank_to_rating($i + 1, $totalTeams),
            'drivers' => $driversByTeam[$name] ?? [],
        ];
    }
    // teams zonder (nog) bekende coureurs in de stand overslaan (geen bruikbare roster)
    $teams = array_values(array_filter($teams, fn($t) => count($t['drivers']) === 2));

    if ($teams) file_put_contents($cacheFile, json_encode($teams));
    return $teams;
}
