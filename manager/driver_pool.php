<?php
// Vaste pool van fictieve coureurs waaruit een speler er 2 kiest bij teamaanmaak.
// Bewust fictief (geen echte coureursnamen) om verwarring met de rest van de site te voorkomen.
const F1HUB_DRIVER_POOL = [
    ['id' => 1,  'name' => 'Kian Voss',        'pace' => 88, 'consistency' => 70],
    ['id' => 2,  'name' => 'Rafael Dumont',    'pace' => 82, 'consistency' => 85],
    ['id' => 3,  'name' => 'Noah Lindqvist',   'pace' => 75, 'consistency' => 90],
    ['id' => 4,  'name' => 'Enzo Marchetti',   'pace' => 91, 'consistency' => 60],
    ['id' => 5,  'name' => 'Lucas Oyelaran',   'pace' => 79, 'consistency' => 78],
    ['id' => 6,  'name' => 'Mateus Albrecht',  'pace' => 85, 'consistency' => 74],
    ['id' => 7,  'name' => 'Theo van Dongen',  'pace' => 72, 'consistency' => 88],
    ['id' => 8,  'name' => 'Ivo Castellano',   'pace' => 80, 'consistency' => 80],
    ['id' => 9,  'name' => 'Aksel Berglund',   'pace' => 77, 'consistency' => 83],
    ['id' => 10, 'name' => 'Diego Fontaine',   'pace' => 89, 'consistency' => 65],
    ['id' => 11, 'name' => 'Marco Reinholt',   'pace' => 68, 'consistency' => 92],
    ['id' => 12, 'name' => 'Yusuf Aksoy',      'pace' => 83, 'consistency' => 77],
];

function f1hub_driver_from_pool($id) {
    foreach (F1HUB_DRIVER_POOL as $d) {
        if ($d['id'] === (int)$id) return $d;
    }
    return null;
}
