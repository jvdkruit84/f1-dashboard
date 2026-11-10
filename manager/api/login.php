<?php
require __DIR__ . '/../db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    f1hub_json(['ok' => false, 'error' => 'Alleen POST toegestaan.'], 405);
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$pdo = f1hub_db();
$stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    f1hub_json(['ok' => false, 'error' => 'Onjuiste gebruikersnaam of wachtwoord.'], 401);
}

$_SESSION['f1hub_manager_user_id'] = (int)$user['id'];
$_SESSION['f1hub_manager_username'] = $user['username'];

f1hub_json(['ok' => true, 'username' => $user['username']]);
