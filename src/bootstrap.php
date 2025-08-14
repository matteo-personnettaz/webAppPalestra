<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require __DIR__ . '/../vendor/autoload.php'; // vendor in root progetto, src in /src

use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;

// === CONFIG DB ===
const DB_DSN  = 'mysql:unix_socket=' . getenv('DB_SOCKET') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
const DB_USER = 'palestra_athena';
const DB_PASS = getenv('DB_PASS');

// === FUNZIONI DB ===
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// === RISPOSTE API ===
function respond($data = [], int $code = 200): void {
    http_response_code($code);
    if (is_array($data) && !isset($data['success'])) {
        $data = ['success' => true, 'data' => $data];
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// === AUTENTICAZIONE ===
function verify_firebase_token(): string {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        fail('Token mancante', 401);
    }
    $idToken = $matches[1];

    try {
        $factory = (new Factory)
            ->withServiceAccount(__DIR__ . '/../srl/firebase_key.json'); // chiave privata
        $auth = $factory->createAuth();
        $verifiedIdToken = $auth->verifyIdToken($idToken);
        return $verifiedIdToken->claims()->get('sub'); // UID utente
    } catch (\Throwable $e) {
        fail('Token non valido: ' . $e->getMessage(), 401);
    }
}

// === CHECK API KEY (per test vecchi) ===
function check_api_key(): void {
    $key = $_GET['key'] ?? $_POST['key'] ?? '';
    if ($key !== getenv('API_KEY')) {
        fail('Invalid API key', 401);
    }
}
