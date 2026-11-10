<?php
require __DIR__ . '/../db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    f1hub_json(['ok' => false, 'error' => 'Alleen POST toegestaan.'], 405);
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$email = trim((string)($_POST['email'] ?? ''));

if (!preg_match('/^[a-zA-Z0-9_\-]{3,40}$/', $username)) {
    f1hub_json(['ok' => false, 'error' => 'Gebruikersnaam moet 3-40 tekens zijn (letters, cijfers, _ of -).'], 422);
}
if (strlen($password) < 8) {
    f1hub_json(['ok' => false, 'error' => 'Wachtwoord moet minstens 8 tekens zijn.'], 422);
}

$pdo = f1hub_db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    f1hub_json(['ok' => false, 'error' => 'Die gebruikersnaam is al in gebruik.'], 409);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
$stmt->execute([$username, $hash, $email !== '' ? $email : null]);

$_SESSION['f1hub_manager_user_id'] = (int)$pdo->lastInsertId();
$_SESSION['f1hub_manager_username'] = $username;

f1hub_json(['ok' => true, 'username' => $username]);
