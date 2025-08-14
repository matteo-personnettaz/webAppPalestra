<?php
declare(strict_types=1);

/* ===== CORS & JSON ===== */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ===== Composer ===== */
require __DIR__ . '/../vendor/autoload.php';

/* ===== CONFIG DA ENV =====
   Valori impostati dal deploy Cloud Run (cloudbuild.yaml)
*/
$CFG = [
  'DB_NAME'   => getenv('DB_NAME')   ?: 'fitness_db',
  'DB_USER'   => getenv('DB_USER')   ?: '',
  'DB_PASS'   => getenv('DB_PASS')   ?: '',
  'DB_SOCKET' => getenv('DB_SOCKET') ?: '',       // es: /cloudsql/project:region:instance
  'DB_HOST'   => getenv('DB_HOST')   ?: '',       // opzionale per test TCP
  'DB_PORT'   => (int)(getenv('DB_PORT') ?: 3306),
  'FIREBASE_CREDENTIALS_PATH' => getenv('FIREBASE_CREDENTIALS_PATH') ?: '', // es: /var/www/html/secure/service-account.json
  'API_KEY'   => getenv('API_KEY') ?: '',         // solo se ti serve ancora per rotte legacy
];

/* ===== DB: connessione (preferisci UNIX socket, poi TCP) ===== */
function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  global $CFG;
  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];

  $tried = [];
  // 1) UNIX socket (Cloud SQL su Cloud Run)
  if (!empty($CFG['DB_SOCKET'])) {
    $dsn = "mysql:unix_socket={$CFG['DB_SOCKET']};dbname={$CFG['DB_NAME']};charset=utf8mb4";
    $tried[] = $dsn;
    try {
      $pdo = new PDO($dsn, $CFG['DB_USER'], $CFG['DB_PASS'], $opt);
      return $pdo;
    } catch (Throwable $e) {
      // continua su TCP
    }
  }
  // 2) TCP (sviluppo / proxy)
  if (!empty($CFG['DB_HOST'])) {
    $dsn = "mysql:host={$CFG['DB_HOST']};port={$CFG['DB_PORT']};dbname={$CFG['DB_NAME']};charset=utf8mb4";
    $tried[] = $dsn;
    try {
      $pdo = new PDO($dsn, $CFG['DB_USER'], $CFG['DB_PASS'], $opt);
      return $pdo;
    } catch (Throwable $e) {
      // fall-through
    }
  }

  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error'   => 'Connessione DB fallita',
    'details' => ['dsn_tried' => $tried],
  ]);
  exit;
}

/* ===== Helpers risposta ===== */
function respond($data = [], int $code = 200): void {
  http_response_code($code);
  if (is_array($data) && !isset($data['success'])) {
    $data = ['success'=>true,'data'=>$data];
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  $payload = ['success'=>false,'error'=>$msg];
  if ($extra) $payload['details'] = $extra;
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ===== Auth header parsing ===== */
function bearer_token(): string {
  $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    fail('Token mancante', 401);
  }
  return $m[1];
}

/* ===== Verifica ID Token Firebase ===== */
function verify_firebase_token(): string {
  global $CFG;
  $idToken = bearer_token();

  // Percorso credenziali montate da Secret Manager (cloudbuild -> --set-secrets)
  $saPath = $CFG['FIREBASE_CREDENTIALS_PATH'];
  if (!$saPath || !file_exists($saPath)) {
    fail('Credenziali Firebase non trovate. Controlla FIREBASE_CREDENTIALS_PATH e il montaggio dei segreti.', 500, [
      'FIREBASE_CREDENTIALS_PATH' => $saPath ?: '(vuoto)',
    ]);
  }

  try {
    $factory = (new Factory())->withServiceAccount($saPath);
    $auth = $factory->createAuth();
    $verified = $auth->verifyIdToken($idToken);
    /** UID dell’utente autenticato */
    return $verified->claims()->get('sub');
  } catch (Throwable $e) {
    fail('Token non valido: '.$e->getMessage(), 401);
  }
}

/* ===== Shortcut per ottenere l’UID corrente ===== */
function current_uid(): string {
  return verify_firebase_token();
}

/* ===== (OPZ) API key legacy, se ti serve ancora per test vecchi ===== */
function check_api_key(): void {
  global $CFG;
  $key = $_GET['key'] ?? $_POST['key'] ?? '';
  if ($CFG['API_KEY'] === '' || $key !== $CFG['API_KEY']) {
    fail('Invalid API key', 401);
  }
}
