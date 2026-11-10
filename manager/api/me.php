<?php
require __DIR__ . '/../db.php';
session_start();

if (empty($_SESSION['f1hub_manager_user_id'])) {
    f1hub_json(['ok' => true, 'loggedIn' => false]);
}

f1hub_json([
    'ok' => true,
    'loggedIn' => true,
    'username' => $_SESSION['f1hub_manager_username'] ?? '',
]);
