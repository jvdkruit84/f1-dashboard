<?php
function f1hub_db() {
    static $pdo = null;
    if ($pdo === null) {
        $configFile = __DIR__ . '/db-config.php';
        if (!file_exists($configFile)) {
            http_response_code(500);
            die(json_encode(['ok' => false, 'error' => 'db-config.php ontbreekt. Upload dit bestand apart via FTP.']));
        }
        require $configFile;
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['ok' => false, 'error' => 'Databaseverbinding mislukt.']));
        }
    }
    return $pdo;
}

function f1hub_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function f1hub_require_login() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['f1hub_manager_user_id'])) {
        f1hub_json(['ok' => false, 'error' => 'Niet ingelogd.'], 401);
    }
    return (int)$_SESSION['f1hub_manager_user_id'];
}
