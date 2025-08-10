<?php
header('Content-Type: text/plain');

echo "ENV\n";
echo "DB_SOCKET=" . getenv('DB_SOCKET') . "\n";
echo "DB_NAME="   . getenv('DB_NAME')   . "\n";
echo "DB_USER="   . getenv('DB_USER')   . "\n";

echo "\nCLOUDSQL DIR\n";
$dir = '/cloudsql';
if (is_dir($dir)) {
  echo "$dir exists\n";
  echo "scandir($dir):\n";
  print_r(@scandir($dir));
} else {
  echo "$dir DOES NOT EXIST\n";
}

$socket = getenv('DB_SOCKET');
echo "\nSOCKET PATH\n";
echo file_exists($socket) ? "$socket exists\n" : "$socket NOT FOUND\n";

echo "\nPDO TEST\n";
try {
  $dsn = "mysql:unix_socket=$socket;dbname=" . getenv('DB_NAME') . ";charset=utf8mb4";
  $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
  ]);
  echo "PDO CONNECTED\n";
  $row = $pdo->query("SELECT NOW() AS now")->fetch(PDO::FETCH_ASSOC);
  echo "NOW(): " . $row['now'] . "\n";
} catch (Throwable $e) {
  echo "PDO ERROR: " . $e->getMessage() . "\n";
}
