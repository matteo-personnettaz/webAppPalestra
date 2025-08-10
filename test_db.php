<?php
header('Content-Type: application/json');

// === Recupero variabili d'ambiente ===
$DB_NAME   = getenv('DB_NAME')   ?: '';
$DB_USER   = getenv('DB_USER')   ?: '';
$DB_PASS   = getenv('DB_PASS')   ?: '';
$DB_SOCKET = getenv('DB_SOCKET') ?: '';

$result = [
    'env' => [
        'DB_NAME'   => $DB_NAME,
        'DB_USER'   => $DB_USER,
        'DB_SOCKET' => $DB_SOCKET
    ]
];

try {
    // Connessione via Unix Socket
    $dsn = "mysql:unix_socket={$DB_SOCKET};dbname={$DB_NAME};charset=utf8mb4";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $result['db_status'] = 'connected';

    // Query di test
    $stmt = $pdo->query("SELECT * FROM CLIENTI LIMIT 5");
    $result['rows'] = $stmt->fetchAll();

} catch (PDOException $e) {
    http_response_code(500);
    $result['error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
