<?php
header('Content-Type: text/plain');

$DB_SOCKET = getenv('DB_SOCKET') ?: '';
$DB_NAME   = getenv('DB_NAME') ?: '';
$DB_USER   = getenv('DB_USER') ?: '';
$DB_PASS   = getenv('DB_PASS') ?: '';

echo "DB_SOCKET: $DB_SOCKET\n";
echo "Dir /cloudsql exists? " . (is_dir('/cloudsql') ? 'YES' : 'NO') . "\n";
echo "Socket path exists? " . (file_exists($DB_SOCKET) ? 'YES' : 'NO') . "\n";

try {
  $dsn = "mysql:unix_socket=$DB_SOCKET;dbname=$DB_NAME;charset=utf8mb4";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  echo "PDO connect: OK\n";
  $row = $pdo->query('SELECT 1 AS ok')->fetch();
  var_dump($row);
} catch (Throwable $e) {
  echo "PDO connect ERROR: " . $e->getMessage() . "\n";
}
