<?php
require __DIR__ . '/../db.php';
require __DIR__ . '/../real_teams.php';

f1hub_require_login();

$year = (int)date('Y');
$teams = f1hub_fetch_real_teams($year);

f1hub_json(['ok' => true, 'year' => $year, 'teams' => $teams]);
