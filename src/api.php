<?php
declare(strict_types=1);

/* ===== CORS & JSON ===== */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
header('Content-Type: application/json; charset=utf-8');

/* ===== Composer (SOLO QUI) ===== */
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/send_email.php';
use Kreait\Firebase\Exception\Auth\UserNotFound;

/* ===== Helpers ===== */
function read_authorization_header(): string {
  $hdr =
    $_SERVER['HTTP_AUTHORIZATION'] ??
    $_SERVER['Authorization'] ??
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

  if ($hdr === '') {
    if (function_exists('apache_request_headers'))      $all = apache_request_headers();
    elseif (function_exists('getallheaders'))            $all = getallheaders();
    else                                                 $all = [];
    foreach ($all as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
  }
  return (string)$hdr;
}

function require_uid($auth): string {
  $hdr = read_authorization_header();
  if (!preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing bearer token']);
    exit;
  }
  try {
    $verified = $auth->verifyIdToken($m[1]);
    return (string)$verified->claims()->get('sub'); // Firebase UID
  } catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token: ' . $e->getMessage()]);
    exit;
  }
}

if (!function_exists('generate_temp_password')) {
  function generate_temp_password(int $len = 12): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@$%-_=+';
    $out = '';
    for ($i=0; $i<$len; $i++) {
      $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    return $out;
  }
}

/* ================================
*  Template email (HTML inline)
* ================================ */
function render_brand_email(array $args): string {
  $appName   = $args['appName']   ?? 'Palestra Athena';
  $title     = $args['title']     ?? '';
  $greeting  = $args['greeting']  ?? '';
  $introHtml = $args['introHtml'] ?? '';
  $ctaText   = $args['ctaText']   ?? null;
  $ctaUrl    = $args['ctaUrl']    ?? null;
  $rows      = $args['rows']      ?? [];
  $footer    = $args['footer']    ?? 'Questa è una comunicazione automatica. Non rispondere a questa email.';

  // Nota: usiamo inline CSS per compatibilità con la maggior parte dei client
  $html = '<!doctype html>
    <html lang="it">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title>'.htmlspecialchars($title ?: $appName).'</title>
    </head>
    <body style="margin:0;padding:0;background:#f5f7fb;color:#111;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.45;">
      <span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">
        '.$appName.' - Notifica automatica
      </span>

      <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="padding:24px 12px;">
        <tr>
          <td align="center">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:640px;background:#ffffff;border-radius:12px;border:1px solid #e6e9f0;box-shadow:0 1px 2px rgba(16,24,40,.06);">
              <tr>
                <td style="padding:20px 24px;border-bottom:1px solid #eef1f6;">
                  <div style="font-size:18px;font-weight:700;">'.htmlspecialchars($appName).'</div>
                  '.($title ? '<div style="margin-top:6px;font-size:15px;color:#475467;font-weight:600;">'.htmlspecialchars($title).'</div>' : '').'
                </td>
              </tr>

              <tr>
                <td style="padding:24px;">
                  '.($greeting ? '<p style="margin:0 0 12px 0;">'.nl2br(htmlspecialchars($greeting)).'</p>' : '').'
                  '.($introHtml ?: '').'

                  '.(count($rows) ? '
                  <div style="margin:18px 0;padding:14px 16px;background:#f7fafc;border:1px solid #e6e9f0;border-radius:10px;">
                    '.implode('', array_map(function($r){
                      $label = htmlspecialchars($r["label"] ?? "");
                      $value = htmlspecialchars($r["value"] ?? "");
                      return '<div style="display:flex;justify-content:space-between;gap:16px;padding:6px 0;">
                                <div style="color:#475467;font-size:13px;">'.$label.'</div>
                                <div style="font-weight:700;font-size:13px;">'.$value.'</div>
                              </div>';
                    }, $rows)).'
                  </div>' : '').'

                  '.($ctaText && $ctaUrl ? '
                    <div style="margin:22px 0 10px 0;align-items:center;text-align:center;">
                      <a href="'.htmlspecialchars($ctaUrl).'"
                        style="display:inline-block;padding:12px 18px;background:#d32f2f;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;box-shadow:0 1px 2px rgba(16,24,40,.1);">
                        '.htmlspecialchars($ctaText).'
                      </a>
                    </div>
                    <div style="font-size:12px;color:#667085;">Se il pulsante non funziona, copia e incolla questo link nel browser:
                      <span style="word-break:break-all;color:#344054;">'.htmlspecialchars($ctaUrl).'</span>
                    </div>
                  ' : '').'

                  <hr style="border:none;border-top:1px solid #eef1f6;margin:20px 0;">
                  <p style="margin:0;font-size:12px;color:#667085;text-align:center;">'.$footer.'</p>
                </td>
              </tr>

            </table>

            <div style="margin:12px 0 0 0;color:#98a2b3;font-size:12px;">
              &copy '.date('Y').' '.htmlspecialchars($appName).'
            </div>
          </td>
        </tr>
      </table>
    </body>
    </html>';
  return $html;
}

/* =========================================
*  Email di benvenuto con password temporanea
* ========================================= */
function email_welcome_password(string $to, string $displayName, string $tempPassword): array {
  $appName  = getenv('APP_NAME')      ?: 'Palestra Athena';
  $loginUrl = getenv('APP_LOGIN_URL') ?: 'https://palestra-athena.web.app';

  $html = render_brand_email([
    'appName'  => $appName,
    'title'    => 'Benvenuto nella piattaforma',
    'greeting' => 'Gentile '.($displayName !== '' ? $displayName : $to).',',
    'introHtml'=> '<p style="margin:0 0 10px 0;">L&#39;accesso per la WebApp <b>'.$appName.'</b> &egrave pronto.<br>Di seguito sono riportate le credenziali per l&#39;accesso.</p>',
    'rows'     => [
      ['label' => 'Email',               'value' => $to],
      ['label' => 'Password temporanea', 'value' => $tempPassword],
    ],
    'ctaText'  => 'Accedi alla WebApp',
    'ctaUrl'   => $loginUrl,
    'footer'   => 'Se non hai richiesto questo accesso contatta l&#39;amministratore.',
  ]);

  return sendEmail([
    'to'      => $to,
    'subject' => 'Benvenuto - Accesso e password temporanea',
    'html'    => $html,
  ]);
}

/* =========================================
*  Reset password con nuova grafica
* ========================================= */
function email_temp_password(string $to, string $displayName, string $tempPassword): array {
  $appName  = getenv('APP_NAME')      ?: 'Palestra Athena';
  $loginUrl = getenv('APP_LOGIN_URL') ?: 'https://palestra-athena.web.app';

  $html = render_brand_email([
    'appName'  => $appName,
    'title'    => 'Recupero della password',
    'greeting' => 'Gentile '.($displayName !== '' ? $displayName : $to).',',
    'introHtml'=> '<p style="margin:0 0 10px 0;">come richiesto, abbiamo generato una <b>nuova password temporanea</b>.</p>',
    'rows'     => [
      ['label' => 'Email',               'value' => $to],
      ['label' => 'Password temporanea', 'value' => $tempPassword],
    ],
    'ctaText'  => 'Vai al login',
    'ctaUrl'   => $loginUrl,
    'footer'   => 'Se non hai richiesto il reset, contatta subito l&#39;amministratore.',
  ]);

  return sendEmail([
    'to'      => $to,
    'subject' => 'Reset password - WebApp '.$appName.'',
    'html'    => $html,
  ]);
}


/* ===== API KEY / ACTION / ENV ===== */
$API_KEY = getenv('API_KEY') ?: 'override_me_in_prod';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$key    = $_GET['key']    ?? $_POST['key']    ?? '';

$CFG = [
  'DB_NAME'   => getenv('DB_NAME')   ?: 'fitness_db',
  'DB_USER'   => getenv('DB_USER')   ?: '',
  'DB_PASS'   => getenv('DB_PASS')   ?: '',
  'DB_SOCKET' => getenv('DB_SOCKET') ?: '',
  'DB_HOST'   => getenv('DB_HOST')   ?: '',
  'DB_PORT'   => (int)(getenv('DB_PORT') ?: 3306),
];

/* ===== Rotte pubbliche senza DB ===== */
if ($action === 'ping') {
  echo json_encode(['success' => true, 'message' => 'pong']);
  exit;
}
if ($action === 'whoami') {
  echo json_encode([
    'success' => true,
    'rev' => getenv('K_REVISION') ?: 'n/a',
    'has_socket' => $CFG['DB_SOCKET'] ?: 'n/a',
  ]);
  exit;
}
if ($action === 'socketcheck') {
  echo json_encode([
    'success' => true,
    'dir_exists' => is_dir('/cloudsql'),
    'sock' => $CFG['DB_SOCKET'],
    'sock_exists' => $CFG['DB_SOCKET'] ? file_exists($CFG['DB_SOCKET']) : null,
  ]);
  exit;
}

/* ===== (3.2) Esempi DDL e elenco tabelle (pubblici) ===== */
if ($action === 'schema_examples') {
  echo json_encode([
    'success' => true,
    'ddl' => [
      'UTENTI' => "CREATE TABLE IF NOT EXISTS UTENTI (
  UID          VARCHAR(64) PRIMARY KEY,
  EMAIL        VARCHAR(255) NOT NULL UNIQUE,
  RUOLO        ENUM('ADMIN','CLIENTE') NOT NULL DEFAULT 'CLIENTE',
  ATTIVO       TINYINT(1) NOT NULL DEFAULT 1,
  D_CREATO     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  D_AGG        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
      'CLIENTI' => "CREATE TABLE IF NOT EXISTS CLIENTI (
  ID_CLIENTE     INT AUTO_INCREMENT PRIMARY KEY,
  UID            VARCHAR(64) NULL,
  COGNOME        VARCHAR(100) NOT NULL,
  NOME           VARCHAR(100) NOT NULL,
  DATA_NASCITA   DATE NULL,
  INDIRIZZO      VARCHAR(255) NULL,
  CODICE_FISCALE VARCHAR(32)  NULL UNIQUE,
  TELEFONO       VARCHAR(40)  NULL,
  EMAIL          VARCHAR(255) NULL,
  D_CREATO       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  D_AGG          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (UID),
  CONSTRAINT fk_clienti_utenti FOREIGN KEY (UID) REFERENCES UTENTI(UID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
      // 3.2: tabella di transcodifica Admin <-> Clienti (many-to-many)
      'ADMIN_CLIENTI' => "CREATE TABLE IF NOT EXISTS ADMIN_CLIENTI (
  ADMIN_UID   VARCHAR(64) NOT NULL,
  ID_CLIENTE  INT NOT NULL,
  PRIMARY KEY (ADMIN_UID, ID_CLIENTE),
  KEY (ID_CLIENTE),
  CONSTRAINT fk_ac_admin FOREIGN KEY (ADMIN_UID)  REFERENCES UTENTI(UID)   ON DELETE CASCADE,
  CONSTRAINT fk_ac_client FOREIGN KEY (ID_CLIENTE) REFERENCES CLIENTI(ID_CLIENTE) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    ],
  ]);
  exit;
}
if ($action === 'list_tables') {
  try {
    [$pdoTmp] = connect_pdo($CFG);
    $rows = $pdoTmp->query("SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY TABLE_NAME")->fetchAll();
    echo json_encode(['success'=>true, 'tables'=>$rows]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ===== PDO factory (socket > tcp) ===== */
function connect_pdo(array $cfg): array {
  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];
  $tried = [];
  // UNIX SOCKET
  if (!empty($cfg['DB_SOCKET'])) {
    $dsn = "mysql:unix_socket={$cfg['DB_SOCKET']};dbname={$cfg['DB_NAME']};charset=utf8mb4";
    $tried[] = $dsn;
    try { return [new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], $opt), 'unix_socket', $tried]; }
    catch (Throwable $e) { $last = $e->getMessage(); }
  }
  // TCP
  if (!empty($cfg['DB_HOST'])) {
    $dsn = "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};dbname={$cfg['DB_NAME']};charset=utf8mb4";
    $tried[] = $dsn;
    try { return [new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], $opt), 'tcp', $tried]; }
    catch (Throwable $e) { $last = $e->getMessage(); }
  }
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => 'Connessione DB fallita',
    'details' => ['dsn_tried' => $tried, 'last_error' => $last ?? 'n/a', 'hint' => 'Impostare DB_SOCKET oppure DB_HOST/DB_PORT'],
  ]);
  exit;
}

/* ===== DB CONNECT (serve per diag e rotte protette) ===== */
$publicNoDb = ['ping','whoami','socketcheck','email_test','schema_examples','list_tables'];
$needsDb = ($action === 'diag') || !in_array($action, $publicNoDb, true);

$pdo = null; $connKind = null; $dsnTried = null;
if ($needsDb) { [$pdo, $connKind, $dsnTried] = connect_pdo($CFG); }

/* ===== DIAG (con DB) ===== */
if ($action === 'diag') {
  try {
    $meta = $pdo->query("SELECT NOW() AS now_ts, CURRENT_USER() AS cur_user, USER() AS user_func, DATABASE() AS db, VERSION() AS ver")->fetch();
    $ssl  = $pdo->query("SHOW VARIABLES LIKE 'require_secure_transport'")->fetch();
    $cnt  = $pdo->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE()")->fetch();
    echo json_encode([
      'success' => true,
      'connection' => ['kind' => $connKind, 'dsn_tried' => $dsnTried],
      'meta' => $meta,
      'require_secure_transport' => $ssl['Value'] ?? null,
      'tables_count' => (int)($cnt['c'] ?? 0),
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'dsn_tried' => $dsnTried]);
  }
  exit;
}

/* ===== Auth per rotte protette ===== */ 
$isPublic = in_array($action, ['ping', 'whoami', 'socketcheck', 'diag', 'email_test','provision_if_allowed','schema_examples','list_tables'], true);

$uid = null; $isAdmin = false; $auth = null;

if (!$isPublic) {
  if ($key !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Chiave API non valida']);
    exit;
  }
  $auth = require __DIR__ . '/firebase_admin.php'; // sdk admin
  $uid  = require_uid($auth);

  // ADMIN? (schema nuovo: RUOLO enum)
  try {
    $stmt = $pdo->prepare("SELECT RUOLO FROM UTENTI WHERE UID=?");
    $stmt->execute([$uid]);
    $isAdmin = (strtoupper((string)($stmt->fetchColumn() ?: 'CLIENTE')) === 'ADMIN');
  } catch (Throwable $e) {
    $isAdmin = false;
  }
}

/* ===== Funzioni di ownership ===== */
function is_admin(PDO $pdo, string $uid): bool {
  $q = $pdo->prepare("SELECT 1 FROM UTENTI WHERE UID=? AND RUOLO='ADMIN' AND ATTIVO=1 LIMIT 1");
  $q->execute([$uid]);
  return (bool)$q->fetchColumn();
}

function require_client_exists(PDO $pdo, int $cid): void {
  $q = $pdo->prepare("SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? LIMIT 1");
  $q->execute([$cid]);
  if (!$q->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'CLIENTE non trovato']);
    exit;
  }
}

/**
 * Accesso: 
 *  - ADMIN: deve avere il cliente assegnato (ADMIN_CLIENTI)
 *  - USER: deve essere proprietario (CLIENTI.UID = $uid)
 */
function require_can_access_client(PDO $pdo, int $cid, string $uid): void {
  if ($cid <= 0) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'ID_CLIENTE non valido']);
    exit;
  }

  if (is_admin($pdo, $uid)) {
    require_client_exists($pdo, $cid);
    require_admin_can_access_client($pdo, $uid, $cid);
    return;
  }

  $q = $pdo->prepare("SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=? LIMIT 1");
  $q->execute([$cid, $uid]);
  if (!$q->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'require_can_access_client - ID_CLIENTE not found or not owned']);
    exit;
  }
}

function admin_can_access_client(PDO $pdo, string $adminUid, int $clientId): bool {
  $q = $pdo->prepare("SELECT 1 FROM ADMIN_CLIENTI WHERE ADMIN_UID=? AND ID_CLIENTE=? LIMIT 1");
  $q->execute([$adminUid, $clientId]);
  return (bool)$q->fetchColumn();
}

function require_admin_can_access_client(PDO $pdo, string $adminUid, int $clientId): void {
  if (!admin_can_access_client($pdo, $adminUid, $clientId)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Cliente non assegnato a questo admin']);
    exit;
  }
}

//Per creare il nome visualizzato NOME + COGNOME + (ADM se admin)
function build_display_name(string $first, string $last, bool $isAdmin = false): string {
  $name = trim("$first $last");
  if ($isAdmin) {
    // se non hai nome/cognome, metti solo ADM
    return $name === '' ? 'ADM' : "$name ADM";
  }
  return $name;
}



/* ===== ROUTING ===== */
try {
  switch ($action) {

    /* =========================
    *  Bootstrap utente (crea/aggiorna riga UTENTI)
    * ========================= */
    case 'bootstrap_user':
      $email = trim($_POST['email'] ?? '');
      $displayName = trim($_POST['displayName'] ?? '');

      // opzionale: promozione automatica via env (es. "owner@site.com,admin@site.com")
      $adminEmails = array_filter(array_map('trim', explode(',', getenv('ADMIN_EMAILS') ?: '')));
      $shouldBeAdmin = $email !== '' && in_array(strtolower($email), array_map('strtolower', $adminEmails), true);

      $sql = "INSERT INTO UTENTI (UID, EMAIL, RUOLO)
              VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$uid, $email, $shouldBeAdmin ? 'ADMIN' : 'CLIENTE']);

      // Rileggi il ruolo effettivo
      $stmt = $pdo->prepare("SELECT RUOLO FROM UTENTI WHERE UID=?");
      $stmt->execute([$uid]);
      $ruolo = strtoupper((string)($stmt->fetchColumn() ?: 'CLIENTE'));
      $isAdmin = ($ruolo === 'ADMIN');

      echo json_encode(['success' => true, 'is_admin' => $isAdmin, 'ruolo' => $ruolo]);
      break;

    case 'email_test': {
      $to   = $_GET['to']   ?? $_POST['to']   ?? '';
      $subj = $_GET['subj'] ?? 'Email di prova';
      $res  = sendEmail([
        'to'      => $to,
        'subject' => $subj,
        'html'    => '<p>Funziona! ✅</p>',
      ]);
      echo json_encode(['success' => $res['ok'] === true, 'details' => $res]);
      break;
    }

    /* =========================
    *  ADMIN: CREAZIONE CLIENTE + UTENTE
    * ========================= */
    case 'admin_create_admin': {
      // Solo admin autenticati
      if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Solo admin']);
        break;
      }

      $email   = trim($_POST['email']   ?? '');
      $name    = trim($_POST['name']    ?? '');
      $surname = trim($_POST['surname'] ?? '');
      $display = build_display_name($name, $surname, true);

      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $display === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri non validi (email/nome/cognome)']);
        break;
      }

      // Se esiste già un ADMIN con questa email → blocca
      $chk = $pdo->prepare("SELECT RUOLO, UID FROM UTENTI WHERE EMAIL=? LIMIT 1");
      $chk->execute([$email]);
      $rowUser = $chk->fetch();
      if ($rowUser && strtoupper((string)$rowUser['RUOLO']) === 'ADMIN') {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'Email già associata a un utente ADMIN']);
        break;
      }

      // Crea/aggiorna utente Firebase con password temporanea
      $tempPass = generate_temp_password(12);
      try {
        try {
          $fu = $auth->getUserByEmail($email);
          $uidNew = $fu->uid;
          $auth->updateUser($uidNew, [
            'password'     => $tempPass,
            'displayName'  => $display,
            'disabled'     => false,
          ]);
        } catch (UserNotFound $e) {
          $created = $auth->createUser([
            'email'         => $email,
            'password'      => $tempPass,
            'displayName'   => $display,
            'emailVerified' => false,
            'disabled'      => false,
          ]);
          $uidNew = $created->uid;
        }
      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore Firebase: '.$e->getMessage()]);
        break;
      }

      // Upsert in UTENTI come ADMIN
      try {
        $pdo->prepare("
          INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
          VALUES (?,?, 'ADMIN', 1)
          ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL), RUOLO='ADMIN', ATTIVO=1
        ")->execute([$uidNew, $email]);

        // opzionale: se la stessa email fosse presente in CLIENTI, non obbligatorio rimuoverla
        // ma se vuoi evitare conflitti puoi eventualmente disaccoppiare:
        // $pdo->prepare('UPDATE CLIENTI SET UID=NULL WHERE EMAIL=?')->execute([$email]);

        // invia credenziali
        $mailRes = email_welcome_password($email, $display, $tempPass);

        echo json_encode([
          'success'    => ($mailRes['ok'] ?? false) === true,
          'uid'        => $uidNew,
          'email_sent' => $mailRes,
        ]);
      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore DB: '.$e->getMessage()]);
      }
      break;
    }

    case 'admin_create_client': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      // Dati cliente
      $lastName   = trim($_POST['lastName']   ?? '');
      $firstName  = trim($_POST['firstName']  ?? '');
      $phone      = ($_POST['phone']      ?? '') ?: null;
      $email      = trim($_POST['email']  ?? '');
      $display = build_display_name($firstName, $lastName, true);

      if ($lastName === '' || $firstName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri obbligatori mancanti o email non valida']);
        break;
      }

      // Se esiste già in UTENTI con ruolo ADMIN → blocca
      $chk = $pdo->prepare("SELECT RUOLO, UID FROM UTENTI WHERE EMAIL=?");
      $chk->execute([$email]);
      $rowUser = $chk->fetch();
      if ($rowUser && strtoupper((string)$rowUser['RUOLO']) === 'ADMIN') {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'Email associata a un ADMIN: non creabile come CLIENTE']);
        break;
      }

      // 1) Crea/aggiorna utente Firebase con password temporanea
      $tempPass = generate_temp_password(12);
      try {
        if ($rowUser) {
          try {
            $fu = $auth->getUserByEmail($email);
            $uidNew = $fu->uid;
            $auth->updateUser($uidNew, [
              'password'     => $tempPass,
              'displayName'  => $display,
              'disabled'     => false,
            ]);
          } catch (UserNotFound $e) {
            $created = $auth->createUser([
              'email'        => $email,
              'password'     => $tempPass,
              'displayName'  => $display,
              'emailVerified'=> false,
              'disabled'     => false,
            ]);
            $uidNew = $created->uid;
          }
        } else {
          try {
            $fu = $auth->getUserByEmail($email);
            $uidNew = $fu->uid;
            $auth->updateUser($uidNew, [
              'password'     => $tempPass,
              'displayName'  => $display,
              'disabled'     => false,
            ]);
          } catch (UserNotFound $e) {
            $created = $auth->createUser([
              'email'        => $email,
              'password'     => $tempPass,
              'displayName'  => $display,
              'emailVerified'=> false,
              'disabled'     => false,
            ]);
            $uidNew = $created->uid;
          }
        }
      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore Firebase: '.$e->getMessage()]);
        break;
      }

      // 2) Transazione DB: UTENTI (CLIENTE) → CLIENTI (FK su UID)
      try {
        $pdo->beginTransaction();

        $pdo->prepare("
          INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
          VALUES (?,?, 'CLIENTE', 1)
          ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL)
        ")->execute([$uidNew, $email]);

        $stmt = $pdo->prepare('
          INSERT INTO CLIENTI
            (UID, COGNOME, NOME, TELEFONO, EMAIL)
          VALUES (?,?,?,?,?)
        ');
        $stmt->execute([
          $uidNew, $lastName, $firstName, $phone, $email
        ]);

        $clientId = (int)$pdo->lastInsertId();

        // 3.3 (assegnazione): collega il nuovo cliente a QUESTO admin
        $pdo->prepare("INSERT IGNORE INTO ADMIN_CLIENTI (ADMIN_UID, ID_CLIENTE) VALUES (?,?)")
            ->execute([$uid, $clientId]);

        $pdo->commit();

        // Email di benvenuto
        $mailRes = email_welcome_password($email, trim("$firstName $lastName"), $tempPass);

        echo json_encode([
          'success'     => ($mailRes['ok'] ?? false) === true,
          'id_cliente'  => $clientId,
          'uid'         => $uidNew,
          'email_sent'  => $mailRes,
        ]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'Creazione cliente fallita','details'=>$e->getMessage()]);
      }
      break;
    }

    case 'provision_if_allowed': {
      // PROTECTED BY API KEY
      if ($key !== $API_KEY) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Chiave API non valida']);
        break;
      }

      $email = trim($_POST['email'] ?? '');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Email non valida']);
        break;
      }

      $q = $pdo->prepare("
        SELECT
          ID_CLIENTE,
          UID,
          NOME,
          COGNOME,
          FIRST_NAME,
          LAST_NAME
        FROM CLIENTI
        WHERE EMAIL = ?
        LIMIT 1
      ");
      $q->execute([$email]);
      $row = $q->fetch();
      if (!$row) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Email non presente tra i clienti']);
        break;
      }

      $first = trim($row['NOME'] ?? ($row['FIRST_NAME'] ?? ''));
      $last  = trim($row['COGNOME'] ?? ($row['LAST_NAME'] ?? ''));
      $fullName = trim("$first $last");

      $auth = require __DIR__ . '/firebase_admin.php';

      try {
        $fu = $auth->getUserByEmail($email);
        $uidNew = $fu->uid;
        if ($fullName !== '' && $fu->displayName !== $fullName) {
          $auth->updateUser($uidNew, ['displayName' => $fullName]);
        }
      } catch (UserNotFound $e) {
        $tempPass = generate_temp_password(12);
        $data = [
          'email'         => $email,
          'password'      => $tempPass,
          'emailVerified' => false,
          'disabled'      => false,
        ];
        if ($fullName !== '') $data['displayName'] = $fullName;
        $created = $auth->createUser($data);
        $uidNew  = $created->uid;
      }

      $pdo->prepare("
        INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
        VALUES (?,?, 'CLIENTE', 1)
        ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL), ATTIVO=1
      ")->execute([$uidNew, $email]);

      $pdo->prepare("
        UPDATE CLIENTI
        SET UID = ?
        WHERE EMAIL = ?
          AND (UID IS NULL OR UID = '')
      ")->execute([$uidNew, $email]);

      echo json_encode([
        'success' => true,
        'uid'     => $uidNew,
        'displayName' => $fullName,
      ]);
      break;
    }

    /* =========================
    *  ADMIN: invia "benvenuto" (credenziali) al CLIENTE
    * ========================= */
    case 'admin_send_welcome': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $clientId = (int)($_POST['clientId'] ?? 0);
      if (!$clientId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'clientId obbligatorio']); break; }
      require_admin_can_access_client($pdo, $uid, $clientId);

      $q = $pdo->prepare("SELECT c.ID_CLIENTE, c.NOME, c.COGNOME, c.EMAIL, u.UID
                          FROM CLIENTI c
                          JOIN UTENTI u ON u.UID = c.UID
                          WHERE c.ID_CLIENTE=? LIMIT 1");
      $q->execute([$clientId]);
      $row = $q->fetch();

      if (!$row || !filter_var($row['EMAIL'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Cliente non trovato o email non valida']);
        break;
      }

      $email    = (string)$row['EMAIL'];
      $fullName = trim(($row['NOME'] ?? '').' '.($row['COGNOME'] ?? ''));
      $uidUser  = (string)$row['UID'];

      $tempPass = generate_temp_password(12);
      try {
        $auth->updateUser($uidUser, [
          'password'    => $tempPass,
          'displayName' => $fullName,
          'disabled'    => false,
        ]);
      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Firebase updateUser failed: '.$e->getMessage()]);
        break;
      }

      $mailRes = email_welcome_password($email, $fullName, $tempPass);
      echo json_encode(['success'=>($mailRes['ok'] ?? false) === true, 'details'=>$mailRes]);
      break;
    }

    /* =========================
    *  ADMIN: reset password (CLIENTE) e invio email
    * ========================= */
    case 'admin_reset_password': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $clientId = (int)($_POST['clientId'] ?? 0);
      if (!$clientId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'clientId obbligatorio']); break; }
      require_admin_can_access_client($pdo, $uid, $clientId);

      $q = $pdo->prepare("SELECT c.ID_CLIENTE, c.NOME, c.COGNOME, c.EMAIL, u.UID
                          FROM CLIENTI c
                          JOIN UTENTI u ON u.UID = c.UID
                          WHERE c.ID_CLIENTE=? LIMIT 1");
      $q->execute([$clientId]);
      $row = $q->fetch();

      if (!$row || !filter_var($row['EMAIL'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Cliente non trovato o email non valida']);
        break;
      }

      $email    = (string)$row['EMAIL'];
      $fullName = trim(($row['NOME'] ?? '').' '.($row['COGNOME'] ?? ''));
      $uidUser  = (string)$row['UID'];

      $tempPass = generate_temp_password(12);
      try {
        $auth->updateUser($uidUser, [
          'password'    => $tempPass,
          'displayName' => $fullName,
          'disabled'    => false,
        ]);
      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Firebase updateUser failed: '.$e->getMessage()]);
        break;
      }

      $mailRes = email_temp_password($email, $fullName, $tempPass);
      echo json_encode(['success'=>($mailRes['ok'] ?? false) === true, 'details'=>$mailRes]);
      break;
    }

    /* =========================
    *  ADMIN: gestione mappature (3.3 / 3.4)
    * ========================= */
    case 'admin_link_client': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $clientId = (int)($_POST['clientId'] ?? 0);
      require_client_exists($pdo, $clientId);
      $pdo->prepare("INSERT IGNORE INTO ADMIN_CLIENTI (ADMIN_UID, ID_CLIENTE) VALUES (?,?)")
          ->execute([$uid, $clientId]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'admin_unlink_client': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $clientId = (int)($_POST['clientId'] ?? 0);
      $pdo->prepare("DELETE FROM ADMIN_CLIENTI WHERE ADMIN_UID=? AND ID_CLIENTE=?")
          ->execute([$uid, $clientId]);
      echo json_encode(['success'=>true]);
      break;
    }

    // 3.3: bulk link
    case 'admin_link_clients_bulk': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $ids = trim($_POST['clientIds'] ?? '');
      if ($ids === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'clientIds mancante']); break; }
      $arr = array_filter(array_map('intval', explode(',', $ids)));
      if (!$arr) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Nessun id valido']); break; }
      $pdo->beginTransaction();
      $ins = $pdo->prepare("INSERT IGNORE INTO ADMIN_CLIENTI (ADMIN_UID, ID_CLIENTE) VALUES (?,?)");
      foreach ($arr as $cid) { $ins->execute([$uid, $cid]); }
      $pdo->commit();
      echo json_encode(['success'=>true, 'linked'=>count($arr)]);
      break;
    }

    // 3.4: set completo (sostituisce i link dell'admin con quelli passati)
    case 'admin_set_client_links': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $ids = trim($_POST['clientIds'] ?? '');
      $arr = $ids === '' ? [] : array_filter(array_map('intval', explode(',', $ids)));
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM ADMIN_CLIENTI WHERE ADMIN_UID=?")->execute([$uid]);
      if ($arr) {
        $ins = $pdo->prepare("INSERT IGNORE INTO ADMIN_CLIENTI (ADMIN_UID, ID_CLIENTE) VALUES (?,?)");
        foreach ($arr as $cid) { $ins->execute([$uid, $cid]); }
      }
      $pdo->commit();
      echo json_encode(['success'=>true, 'set_count'=>count($arr)]);
      break;
    }

    // util: lista clienti assegnati a me (admin)
    case 'get_admin_clients': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $stmt = $pdo->prepare("
        SELECT c.ID_CLIENTE, c.UID, c.COGNOME, c.NOME, c.DATA_NASCITA, c.INDIRIZZO, c.CODICE_FISCALE, c.TELEFONO, c.EMAIL
        FROM CLIENTI c
        JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=c.ID_CLIENTE
        WHERE ac.ADMIN_UID=?
        ORDER BY c.COGNOME, c.NOME
      ");
      $stmt->execute([$uid]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    // util: lista admin collegati a un cliente
    case 'get_client_admins': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $clientId = (int)($_GET['clientId'] ?? $_POST['clientId'] ?? 0);
      require_client_exists($pdo, $clientId);
      $stmt = $pdo->prepare("
        SELECT u.UID, u.EMAIL
        FROM ADMIN_CLIENTI ac
        JOIN UTENTI u ON u.UID=ac.ADMIN_UID
        WHERE ac.ID_CLIENTE=?
        ORDER BY u.EMAIL
      ");
      $stmt->execute([$clientId]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }


    /* =========================
    *        CLIENTI
    * ========================= */
    case 'get_clienti': {
      if ($isAdmin) {
        $stmt = $pdo->prepare('
          SELECT c.ID_CLIENTE, c.UID, c.COGNOME, c.NOME, c.TELEFONO, c.EMAIL
          FROM CLIENTI c
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE = c.ID_CLIENTE
          WHERE ac.ADMIN_UID = ?
          ORDER BY c.COGNOME, c.NOME
        ');
        $stmt->execute([$uid]);
      } else {
        $stmt = $pdo->prepare('
          SELECT ID_CLIENTE, COGNOME, NOME, TELEFONO, EMAIL
          FROM CLIENTI WHERE UID=? ORDER BY COGNOME, NOME
        ');
        $stmt->execute([$uid]);
      }
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_cliente': {
      // Creazione autonoma di un cliente “sciolto” (non assegnato a admin) – opzionale
      $sql = 'INSERT INTO CLIENTI (COGNOME, NOME, TELEFONO, EMAIL)
              VALUES (?, ?, ?, ?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName']   ?? '',
        $_POST['firstName']  ?? '',
        $_POST['phone']      ?? null,
        $_POST['email']      ?? null,
      ]);
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      break;
    }

    case 'update_cliente': {
      $clientId = (int)($_POST['id'] ?? 0);
      require_can_access_client($pdo, $clientId, $uid);

      $sql = 'UPDATE CLIENTI
              SET COGNOME=?,NOME=?,TELEFONO=?,EMAIL=?
              WHERE ID_CLIENTE=?';
      $params = [
        $_POST['lastName']   ?? '',
        $_POST['firstName']  ?? '',
        $_POST['phone']      ?? null,
        $_POST['email']      ?? null,
        $clientId,
      ];
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      echo json_encode(['success' => true]);
      break;
    }

    case 'delete_cliente': {
      $clientId = (int)($_POST['id'] ?? 0);
      require_can_access_client($pdo, $clientId, $uid);

      $sql = 'DELETE FROM CLIENTI WHERE ID_CLIENTE=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$clientId]);
      echo json_encode(['success' => true]);
      break;
    }


    /* =========================
    *       MISURAZIONI
    * ========================= */
    case 'get_misurazioni': {
      $cid = (int)($_GET['clientId'] ?? $_POST['clientId'] ?? 0);
      require_can_access_client($pdo, $cid, $uid);

      $stmt = $pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE=? ORDER BY DATA_MISURAZIONE DESC');
      $stmt->execute([$cid]);
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_misurazione': {
      $cid = (int)($_POST['clientId'] ?? 0);
      require_can_access_client($pdo, $cid, $uid);

      $sql = 'INSERT INTO MISURAZIONI
              (ID_CLIENTE, DATA_MISURAZIONE, PESO, ALTEZZA, TORACE, VITA, FIANCHI, BRACCIO_SX, BRACCIO_DX, COSCIA_SX, COSCIA_DX)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $cid,
        $_POST['date']       ?? date('Y-m-d'),
        $_POST['weight']     ?? null,
        $_POST['height']     ?? null,
        $_POST['chest']      ?? null,
        $_POST['waist']      ?? null,
        $_POST['hips']       ?? null,
        $_POST['leftArm']    ?? null,
        $_POST['rightArm']   ?? null,
        $_POST['leftThigh']  ?? null,
        $_POST['rightThigh'] ?? null,
      ]);
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      break;
    }

    case 'update_misurazione': {
      $cid = (int)($_POST['clientId'] ?? 0);
      require_can_access_client($pdo, $cid, $uid);

      $sql = 'UPDATE MISURAZIONI
              SET ID_CLIENTE=?, DATA_MISURAZIONE=?, PESO=?, ALTEZZA=?, TORACE=?, VITA=?, FIANCHI=?, BRACCIO_SX=?, BRACCIO_DX=?, COSCIA_SX=?, COSCIA_DX=?
              WHERE ID_MISURAZIONE=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $cid,
        $_POST['date']       ?? date('Y-m-d'),
        $_POST['weight']     ?? null,
        $_POST['height']     ?? null,
        $_POST['chest']      ?? null,
        $_POST['waist']      ?? null,
        $_POST['hips']       ?? null,
        $_POST['leftArm']    ?? null,
        $_POST['rightArm']   ?? null,
        $_POST['leftThigh']  ?? null,
        $_POST['rightThigh'] ?? null,
        (int)($_POST['id']    ?? 0),
      ]);
      echo json_encode(['success' => true]);
      break;
    }

    case 'delete_misurazione': {
      $id = (int)($_POST['id'] ?? 0);
      $q = $pdo->prepare("
        SELECT c.UID
        FROM MISURAZIONI m
        JOIN CLIENTI c ON c.ID_CLIENTE = m.ID_CLIENTE
        WHERE m.ID_MISURAZIONE=?");
      $q->execute([$id]);
      $row = $q->fetch();
      if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Measurement not found']); break; }
      if (!$isAdmin && $row['UID'] !== $uid) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Forbidden']); break; }

      $stmt = $pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE=?');
      $stmt->execute([$id]);
      echo json_encode(['success'=>true]);
      break;
    }

    /* =========================
    *        ESERCIZI (globali)
    * ========================= */
    case 'get_esercizi': {
      $stmt = $pdo->query('SELECT * FROM ESERCIZI ORDER BY GRUPPO_MUSCOLARE, NOME');
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_esercizio': {
      $sql = 'INSERT INTO ESERCIZI (SIGLA,NOME,DESCRIZIONE,GRUPPO_MUSCOLARE,VIDEO_URL,IMG_URL) VALUES (?,?,?,?,?,?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla']           ?? null,
        $_POST['nome']            ?? null,
        $_POST['descrizione']     ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl']        ?: null,
        $_POST['imgUrl']          ?: null,
      ]);
      echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
      break;
    }

    case 'update_esercizio': {
      $sql = 'UPDATE ESERCIZI SET SIGLA=?,NOME=?,DESCRIZIONE=?,GRUPPO_MUSCOLARE=?,VIDEO_URL=?,IMG_URL=? WHERE ID_ESERCIZIO=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla']           ?? null,
        $_POST['nome']            ?? null,
        $_POST['descrizione']     ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl']        ?: null,
        $_POST['imgUrl']          ?: null,
        (int)($_POST['id']        ?? 0),
      ]);
      echo json_encode(['success' => true]);
      break;
    }

    case 'delete_esercizio': {
      $stmt = $pdo->prepare('DELETE FROM ESERCIZI WHERE ID_ESERCIZIO=?');
      $stmt->execute([(int)($_POST['id'] ?? 0)]);
      echo json_encode(['success' => true]);
      break;
    }

    /* =========================
    *        APPUNTAMENTI
    * ========================= */
    case 'get_appuntamenti':
      if ($isAdmin) {
        $stmt = $pdo->prepare("
          SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, a.STATO, a.ID_SLOT,
                c.NOME, c.COGNOME
          FROM APPUNTAMENTI a
          JOIN CLIENTI c        ON a.ID_CLIENTE=c.ID_CLIENTE
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=c.ID_CLIENTE
          WHERE ac.ADMIN_UID = ?
          ORDER BY a.DATA_ORA ASC
        ");
        $stmt->execute([$uid]);
      } else {
        $stmt = $pdo->prepare("
          SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, a.STATO, a.ID_SLOT,
                c.NOME, c.COGNOME
          FROM APPUNTAMENTI a
          JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
          WHERE c.UID=?
          ORDER BY a.DATA_ORA ASC
        ");
        $stmt->execute([$uid]);
      }
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;

    case 'update_appuntamento': {
      $cid = (int)($_POST['clientId'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $cid, $uid); }
      else { require_admin_can_access_client($pdo, $uid, $cid); }

      $idApp = (int)($_POST['id'] ?? 0);
      if (!$isAdmin) {
        $chk = $pdo->prepare("SELECT 1
                              FROM APPUNTAMENTI a
                              JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
                              WHERE a.ID_APPUNTAMENTO=? AND c.UID=?");
        $chk->execute([$idApp, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appointment not found']); break; }
      } else {
        $chk = $pdo->prepare("SELECT 1
                              FROM APPUNTAMENTI a
                              JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=a.ID_CLIENTE
                              WHERE a.ID_APPUNTAMENTO=? AND ac.ADMIN_UID=?");
        $chk->execute([$idApp, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appointment not found/assigned']); break; }
      }

      $stmt = $pdo->prepare("UPDATE APPUNTAMENTI
                            SET ID_CLIENTE=?, DATA_ORA=?, TIPOLOGIA=?, NOTE=?
                            WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([
        $cid,
        $_POST['datetime'] ?? date('Y-m-d H:i:s'),
        $_POST['typeCode'] ?? 'GENE',
        $_POST['note']     ?: null,
        $idApp,
      ]);
      echo json_encode(['success' => true]);
      break;
    }

    case 'update_appuntamento_note':
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $id = (int)($_POST['id'] ?? 0);
      $note = ($_POST['note'] ?? '');
      $note = ($note === '') ? null : $note;

      // verifica appartenenza
      $chk = $pdo->prepare("SELECT 1 FROM APPUNTAMENTI a JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=a.ID_CLIENTE WHERE a.ID_APPUNTAMENTO=? AND ac.ADMIN_UID=?");
      $chk->execute([$id, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato/assegnato']); break; }

      $stmt = $pdo->prepare("UPDATE APPUNTAMENTI SET NOTE=? WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$note, $id]);

      if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato']);
        break;
      }
      echo json_encode(['success'=>true]);
      break;


    case 'delete_appuntamento':
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametro id mancante']);
        break;
      }

      try {
        $q = $pdo->prepare("
          SELECT A.ID_APPUNTAMENTO, A.ID_CLIENTE, A.ID_SLOT, C.UID
          FROM APPUNTAMENTI A
          JOIN CLIENTI C ON C.ID_CLIENTE = A.ID_CLIENTE
          WHERE A.ID_APPUNTAMENTO = ?
          LIMIT 1
        ");
        $q->execute([$id]);
        $row = $q->fetch();

        if (!$row) {
          http_response_code(404);
          echo json_encode(['success'=>false,'error'=>'Appointment not found']);
          break;
        }

        if ($isAdmin) {
          require_admin_can_access_client($pdo, $uid, (int)$row['ID_CLIENTE']);
        } else if ($row['UID'] !== $uid) {
          http_response_code(403);
          echo json_encode(['success'=>false,'error'=>'Forbidden']);
          break;
        }

        $pdo->beginTransaction();

        $del = $pdo->prepare("DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=?");
        $del->execute([$id]);

        if (!empty($row['ID_SLOT'])) {
          $upd = $pdo->prepare("UPDATE FASCE_APPUNTAMENTO SET OCCUPATO=0 WHERE ID_SLOT=?");
          $upd->execute([$row['ID_SLOT']]);
        }

        $pdo->commit();
        echo json_encode(['success'=>true]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore cancellazione','details'=>$e->getMessage()]);
      }
      break;


    case 'get_fasce_disponibili': {
      $tipo  = $_GET['tipologia'] ?? $_POST['tipologia'] ?? null;
      $dal   = $_GET['dal']       ?? $_POST['dal']       ?? null; // 'YYYY-MM-DD'
      $al    = $_GET['al']        ?? $_POST['al']        ?? null; // 'YYYY-MM-DD'

      $sql = "SELECT ID_SLOT, TIPOLOGIA, INIZIO, FINE, NOTE
              FROM FASCE_APPUNTAMENTO
              WHERE OCCUPATO=0 AND INIZIO >= NOW()";
      $par = [];

      if ($tipo) { $sql .= " AND TIPOLOGIA=?"; $par[] = $tipo; }
      if ($dal)  { $sql .= " AND DATE(INIZIO) >= ?"; $par[] = $dal; }
      if ($al)   { $sql .= " AND DATE(INIZIO) <= ?"; $par[] = $al; }

      $sql .= " ORDER BY INIZIO ASC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($par);
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'get_fasce_admin': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $tipo  = $_GET['tipologia'] ?? $_POST['tipologia'] ?? null;
      $stato = $_GET['stato']     ?? $_POST['stato']     ?? null; // 'libere' | 'occupate' | 'tutte'
      $dal   = $_GET['dal']       ?? $_POST['dal']       ?? null;
      $al    = $_GET['al']        ?? $_POST['al']        ?? null;

      $sql = "SELECT ID_SLOT, TIPOLOGIA, INIZIO, FINE, OCCUPATO, NOTE
              FROM FASCE_APPUNTAMENTO
              WHERE 1=1";
      $par = [];

      if ($tipo)             { $sql .= " AND TIPOLOGIA=?"; $par[] = $tipo; }
      if ($stato === 'libere')    $sql .= " AND OCCUPATO=0";
      if ($stato === 'occupate')  $sql .= " AND OCCUPATO=1";
      if ($dal)              { $sql .= " AND DATE(INIZIO) >= ?"; $par[] = $dal; }
      if ($al)               { $sql .= " AND DATE(INIZIO) <= ?"; $par[] = $al; }

      $sql .= " ORDER BY INIZIO ASC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($par);
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_fascia': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $tipologia = $_POST['tipologia'] ?? null;
      $inizio    = $_POST['inizio']    ?? null; // 'YYYY-MM-DD HH:MM:SS'
      $fine      = $_POST['fine']      ?? null;
      $note      = $_POST['note']      ?? null;

      if (!$tipologia || !$inizio || !$fine) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parametri tipologia/inizio/fine obbligatori']);
        break;
      }

      try {
        $sql = "INSERT INTO FASCE_APPUNTAMENTO (TIPOLOGIA, INIZIO, FINE, NOTE) VALUES (?,?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tipologia, $inizio, $fine, ($note !== '' ? $note : null)]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
      } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Fascia duplicata o non valida', 'details' => $e->getMessage()]);
      }
      break;
    }

    case 'delete_fascia': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $idSlot = (int)($_POST['id_slot'] ?? 0);
      if (!$idSlot) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_slot obbligatorio']); break; }

      $chk = $pdo->prepare("SELECT OCCUPATO FROM FASCE_APPUNTAMENTO WHERE ID_SLOT=?");
      $chk->execute([$idSlot]);
      $row = $chk->fetch();
      if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Fascia non trovata']); break; }
      if ((int)$row['OCCUPATO'] === 1) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'Fascia occupata, non eliminabile']);
        break;
      }

      $del = $pdo->prepare("DELETE FROM FASCE_APPUNTAMENTO WHERE ID_SLOT=?");
      $del->execute([$idSlot]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'prenota_slot':
      $idSlot    = (int)($_POST['id_slot'] ?? 0);
      $idCliente = (int)($_POST['id_cliente'] ?? 0);
      $note      = $_POST['note'] ?? null;

      if (!$idSlot || !$idCliente) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'id_slot e id_cliente obbligatori']);
        break;
      }

      $own = $pdo->prepare("SELECT UID FROM CLIENTI WHERE ID_CLIENTE=?");
      $own->execute([$idCliente]);
      $rowCli = $own->fetch();
      if (!$rowCli) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Cliente non trovato']);
        break;
      }
      $clienteUid = (string)$rowCli['UID'];

      if (!$isAdmin && $clienteUid !== $uid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cliente non appartenente all’utente']);
        break;
      } else if ($isAdmin) {
        require_admin_can_access_client($pdo, $uid, $idCliente);
      }

      try {
        $pdo->beginTransaction();

        $q = $pdo->prepare("SELECT TIPOLOGIA, INIZIO, FINE, OCCUPATO FROM FASCE_APPUNTAMENTO WHERE ID_SLOT=? FOR UPDATE");
        $q->execute([$idSlot]);
        $s = $q->fetch();
        if (!$s) {
          $pdo->rollBack();
          http_response_code(404);
          echo json_encode(['success' => false, 'error' => 'Fascia non trovata']);
          break;
        }
        if ((int)$s['OCCUPATO'] === 1) {
          $pdo->rollBack();
          http_response_code(409);
          echo json_encode(['success' => false, 'error' => 'Fascia già occupata']);
          break;
        }

        $dupe = $pdo->prepare("
          SELECT COUNT(*) c
          FROM APPUNTAMENTI
          WHERE ID_CLIENTE=? AND DATA_ORA=? AND STATO IN (0,1)
        ");
        $dupe->execute([$idCliente, $s['INIZIO']]);
        if ((int)($dupe->fetch()['c'] ?? 0) > 0) {
          $pdo->rollBack();
          http_response_code(409);
          echo json_encode(['success' => false, 'error' => 'Cliente già prenotato in questa fascia']);
          break;
        }

        $stato = $isAdmin ? 1 : 0;

        $ins = $pdo->prepare("
          INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE, STATO, ID_SLOT)
          VALUES (?,?,?,?,?,?)
        ");
        $ins->execute([
          $idCliente,
          $s['INIZIO'],
          $s['TIPOLOGIA'] ?? 'SED_ORDI',
          ($note !== '' ? $note : null),
          $stato,
          $idSlot,
        ]);

        $pdo->prepare("UPDATE FASCE_APPUNTAMENTO SET OCCUPATO=1 WHERE ID_SLOT=?")->execute([$idSlot]);

        $pdo->commit();

        if (!$isAdmin) {
          $admins = array_filter(array_map('trim', explode(',', getenv('ADMIN_NOTIFY_EMAILS') ?: '')));
          if ($admins) {
            $body = '<p>Nuova richiesta prenotazione</p>'
                  . '<ul>'
                  . '<li>ID Cliente: ' . (int)$idCliente . '</li>'
                  . '<li>Inizio: ' . htmlspecialchars($s['INIZIO']) . '</li>'
                  . '<li>Tipo: ' . htmlspecialchars($s['TIPOLOGIA'] ?? 'SED_ORDI') . '</li>'
                  . '</ul>';
            foreach ($admins as $addr) {
              @sendEmail([
                'to'      => $addr,
                'subject' => 'Richiesta prenotazione slot',
                'html'    => $body,
              ]);
            }
          }
        }

        echo json_encode(['success' => true, 'id_appuntamento' => $pdo->lastInsertId()]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore prenotazione', 'details' => $e->getMessage()]);
      }
      break;



    case 'conferma_appuntamento': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $idApp = (int)($_POST['id_appuntamento'] ?? 0);
      if (!$idApp) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_appuntamento obbligatorio']); break; }

      // check appartenenza
      $chk = $pdo->prepare("SELECT 1 FROM APPUNTAMENTI a JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=a.ID_CLIENTE WHERE a.ID_APPUNTAMENTO=? AND ac.ADMIN_UID=?");
      $chk->execute([$idApp, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato/assegnato']); break; }

      $stmt = $pdo->prepare("UPDATE APPUNTAMENTI SET STATO=1 WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$idApp]);
      if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato']); break; }

      $info = $pdo->prepare("
        SELECT a.DATA_ORA, COALESCE(a.TIPOLOGIA,'SED_ORDI') AS TIPO, c.EMAIL, c.NOME, c.COGNOME
        FROM APPUNTAMENTI a
        JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
        WHERE a.ID_APPUNTAMENTO=?");
      $info->execute([$idApp]);
      $row = $info->fetch();
      if (!empty($row['EMAIL'])) {
        $body = '<p>Gentile ' . htmlspecialchars($row['NOME'] . ' ' . $row['COGNOME']) . ',</p>'
              . '<p>la tua prenotazione è stata <b>approvata</b>.</p>'
              . '<ul>'
              . '<li>Quando: ' . htmlspecialchars($row['DATA_ORA']) . '</li>'
              . '</ul>';
        @sendEmail([
          'to'      => $row['EMAIL'],
          'subject' => 'Appuntamento confermato - ' . htmlspecialchars($row['DATA_ORA']),
          'html'    => $body,
        ]);
      }

      echo json_encode(['success'=>true]);
      break;
    }

    case 'rifiuta_appuntamento': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $idApp = (int)($_POST['id_appuntamento'] ?? 0);
      if (!$idApp) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_appuntamento obbligatorio']); break; }

      // check appartenenza
      $chk = $pdo->prepare("SELECT 1 FROM APPUNTAMENTI a JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=a.ID_CLIENTE WHERE a.ID_APPUNTAMENTO=? AND ac.ADMIN_UID=?");
      $chk->execute([$idApp, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato/assegnato']); break; }

      try {
        $pdo->beginTransaction();

        $q = $pdo->prepare("SELECT ID_SLOT FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=? FOR UPDATE");
        $q->execute([$idApp]);
        $row = $q->fetch();
        if (!$row) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato']); break; }

        $u = $pdo->prepare("UPDATE APPUNTAMENTI SET STATO=2 WHERE ID_APPUNTAMENTO=?");
        $u->execute([$idApp]);

        if ($row['ID_SLOT']) {
          $cnt = $pdo->prepare("SELECT COUNT(*) c FROM APPUNTAMENTI WHERE ID_SLOT=? AND STATO IN (0,1)");
          $cnt->execute([(int)$row['ID_SLOT']]);
          $c = (int)($cnt->fetch()['c'] ?? 0);
          if ($c === 0) {
            $pdo->prepare("UPDATE FASCE_APPUNTAMENTO SET OCCUPATO=0 WHERE ID_SLOT=?")->execute([(int)$row['ID_SLOT']]);
          }
        }

        $pdo->commit();
        
        $info = $pdo->prepare("
          SELECT a.DATA_ORA, COALESCE(a.TIPOLOGIA,'SED_ORDI') AS TIPO, c.EMAIL, c.NOME, c.COGNOME
          FROM APPUNTAMENTI a
          JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
          WHERE a.ID_APPUNTAMENTO=?");
        $info->execute([$idApp]);
        $row = $info->fetch();
        if (!empty($row['EMAIL'])) {
          $body = '<p>Ciao ' . htmlspecialchars($row['NOME'] . ' ' . $row['COGNOME']) . ',</p>'
                . '<p>purtroppo la tua prenotazione è stata <b>rifiutata</b>.</p>'
                . '<p>Riprova a scegliere un altro slot dall’app.</p>';
          @sendEmail([
            'to'      => $row['EMAIL'],
            'subject' => 'Appuntamento rifiutato',
            'html'    => $body,
          ]);
        }

        echo json_encode(['success'=>true]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore rifiuto','details'=>$e->getMessage()]);
      }
      break;
    }

    /* =========================
    *   SCHEDE ESERCIZI (TESTA)
    * ========================= */
    case 'get_schede_testa': {
      $clientId = isset($_GET['clientId']) ? (int)$_GET['clientId'] : (isset($_POST['clientId']) ? (int)$_POST['clientId'] : 0);

      if ($isAdmin) {
        if ($clientId) {
          require_admin_can_access_client($pdo, $uid, $clientId);
          $stmt = $pdo->prepare("
            SELECT st.*
            FROM SCHEDE_ESERCIZI_TESTA st
            WHERE st.ID_CLIENTE=? 
            ORDER BY st.DATA_INIZIO DESC, st.TIPO_SCHEDA ASC, st.ABIL DESC
          ");
          $stmt->execute([$clientId]);
        } else {
          $stmt = $pdo->prepare("
            SELECT st.*
            FROM SCHEDE_ESERCIZI_TESTA st
            JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE = st.ID_CLIENTE
            WHERE ac.ADMIN_UID = ?
            ORDER BY st.ID_CLIENTE ASC, st.DATA_INIZIO DESC, st.TIPO_SCHEDA ASC, st.ABIL DESC
          ");
          $stmt->execute([$uid]);
        }
      } else {
        if ($clientId) {
          require_can_access_client($pdo, $clientId, $uid);
          $stmt = $pdo->prepare("
            SELECT * FROM SCHEDE_ESERCIZI_TESTA 
            WHERE ID_CLIENTE=? 
            ORDER BY DATA_INIZIO DESC, TIPO_SCHEDA ASC, ABIL DESC
          ");
          $stmt->execute([$clientId]);
        } else {
          $stmt = $pdo->prepare("
            SELECT st.*
            FROM SCHEDE_ESERCIZI_TESTA st
            JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
            WHERE c.UID=?
            ORDER BY st.DATA_INIZIO DESC, st.TIPO_SCHEDA ASC, st.ABIL DESC
          ");
          $stmt->execute([$uid]);
        }
      }
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_scheda_testa': {
      $clientId = (int)($_POST['clientId'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $clientId, $uid); }
      else { require_admin_can_access_client($pdo, $uid, $clientId); }

      $tipoScheda = $_POST['tipoScheda'] ?? null; // 'A','B','C'
      $validita   = (int)($_POST['validita'] ?? 2); // mesi
      $dataInizio = $_POST['dataInizio'] ?? date('Y-m-d');
      $note       = ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null;
      $abil       = (int)($_POST['abil'] ?? 1);

      // 1) INSERT
      $sql = "INSERT INTO SCHEDE_ESERCIZI_TESTA
              (ID_CLIENTE, TIPO_SCHEDA, VALIDITA, DATA_INIZIO, NOTE, ABIL)
              VALUES (?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$clientId, $tipoScheda, $validita, $dataInizio, $note, $abil]);

      // 2) Prova a leggere l'ID in modo affidabile
      $insertId = (int)$pdo->lastInsertId();
      if ($insertId === 0) {
        // Fallback robusto: ripesca l'ultima riga per chiave “naturale”
        // (se possibile, aggiungi una UNIQUE su (ID_CLIENTE, DATA_INIZIO, TIPO_SCHEDA, ABIL, NOTE) o almeno timestamp di creazione)
        $stmt2 = $pdo->prepare("
          SELECT ID_SCHEDAT
          FROM SCHEDE_ESERCIZI_TESTA
          WHERE ID_CLIENTE = ? AND DATA_INIZIO = ? AND TIPO_SCHEDA <=> ? AND ABIL = ?
          ORDER BY ID_SCHEDAT DESC
          LIMIT 1
        ");
        $stmt2->execute([$clientId, $dataInizio, $tipoScheda, $abil]);
        $insertId = (int)($stmt2->fetchColumn() ?: 0);
      }

      // (facoltativo) invio mail
      $em = $pdo->prepare("SELECT EMAIL, NOME, COGNOME FROM CLIENTI WHERE ID_CLIENTE=?");
      $em->execute([$clientId]);
      $cli = $em->fetch();

      if (!empty($cli['EMAIL'])) {
        $body = '<p>Ciao ' . htmlspecialchars($cli['NOME'] . ' ' . $cli['COGNOME']) . ',</p>'
              . '<p>è stata creata una nuova scheda <b>' . htmlspecialchars($tipoScheda ?: 'A') . '</b>'
              . ' con validità <b>' . (int)$validita . ' mesi</b> e data inizio <b>' . htmlspecialchars($dataInizio) . '</b>.</p>'
              . '<p>Buon allenamento! 💪</p>';
        @sendEmail([
          'to'      => $cli['EMAIL'],
          'subject' => 'Nuova scheda allenamento',
          'html'    => $body,
        ]);
      }

      echo json_encode(['success'=>true,'insertId'=>$insertId]);
      break;
    }

    case 'update_scheda_testa': {
      $clientId   = (int)($_POST['clientId'] ?? 0);
      $idSchedat  = (int)($_POST['id_schedat'] ?? 0);

      if (!$isAdmin) {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $chk->execute([$idSchedat, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      } else {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND ac.ADMIN_UID=?");
        $chk->execute([$idSchedat, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda non assegnata']); break; }
      }

      $tipoScheda = $_POST['tipoScheda'] ?? null;
      $validita   = (int)($_POST['validita'] ?? 2);
      $dataInizio = $_POST['dataInizio'] ?? date('Y-m-d');
      $note       = ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null;
      $abil       = (int)($_POST['abil'] ?? 1);

      $sql = "UPDATE SCHEDE_ESERCIZI_TESTA
              SET ID_CLIENTE=?, TIPO_SCHEDA=?, VALIDITA=?, DATA_INIZIO=?, NOTE=?, ABIL=?
              WHERE ID_SCHEDAT=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$clientId, $tipoScheda, $validita, $dataInizio, $note, $abil, $idSchedat]);

      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_scheda_testa': {
      $id = (int)($_POST['id'] ?? 0);
      if (!$isAdmin) {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $chk->execute([$id, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      } else {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE=st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND ac.ADMIN_UID=?");
        $chk->execute([$id, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda non assegnata']); break; }
      }
      $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_TESTA WHERE ID_SCHEDAT=?");
      $stmt->execute([$id]);
      echo json_encode(['success'=>true]);
      break;
    }

    /* =========================
    *   SCHEDE ESERCIZI (DETTA)
    * ========================= */
    case 'get_voci_scheda': {
      $idScheda = (int)($_GET['id_scheda'] ?? $_POST['id_scheda'] ?? 0);
      if (!$idScheda) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'ID_SCHEDAT mancante']); break; }

      if (!$isAdmin) {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      } else {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND ac.ADMIN_UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda non assegnata']); break; }
      }

      // 1) Voci della scheda
      $stmt = $pdo->prepare("
        SELECT sd.*
        FROM SCHEDE_ESERCIZI_DETTA sd
        WHERE sd.ID_SCHEDAT=?
        ORDER BY sd.ORDINE
      ");
      $stmt->execute([$idScheda]);
      $voci = $stmt->fetchAll(PDO::FETCH_ASSOC);

      if (!$voci) { echo json_encode(['success'=>true,'data'=>[]]); break; }

      // 2) Serie per le voci trovate
      $ids = array_column($voci, 'ID_SCHEDAD');
      $in  = implode(',', array_fill(0, count($ids), '?'));
      $s2 = $pdo->prepare("
        SELECT ID_SCHEDAD, SERIE, RIPETIZIONI, PESO,
              TECNICA_INTENSITA
        FROM SCHEDE_ESERCIZI_DETTA_PESO
        WHERE ID_SCHEDAD IN ($in)
        ORDER BY ID_SCHEDAD, SERIE
      ");
      $s2->execute($ids);
      $righe = $s2->fetchAll(PDO::FETCH_ASSOC);

      $map = [];
      foreach ($righe as $r) {
        $map[$r['ID_SCHEDAD']][] = [
          'serie'              => (int)$r['SERIE'],
          'ripetizioni'        => (int)$r['RIPETIZIONI'],
          'peso'               => isset($r['PESO']) ? (float)$r['PESO'] : null,
          // chiave nuova: tecnica_intensita (compat in lettura con eventuali client vecchi che cercavano "note")
          'tecnica_intensita'  => ($r['TECNICA_INTENSITA'] ?? null),
        ];
      }

      // 3) Output coerente con vecchia sintassi (+ estensione piramidale)
      $out = [];
      foreach ($voci as $v) {
        $serieList = $map[$v['ID_SCHEDAD']] ?? [];

        if (count($serieList) == 0) {
          // Nessuna riga peso: mantieni compatibilità
          $out[] = array_merge($v, [
            'piramidale'   => false,
            'serie'        => 0,
            'ripetizioni'  => 0,
            'peso'         => null,
            'elenco_serie' => [],
          ]);
        } else if (count($serieList) == 1) {
          // NON piramidale con una sola riga → tieni anche elenco_serie[0] (così rientra tecnica_intensita)
          $s = $serieList[0];
          $out[] = array_merge($v, [
            'piramidale'   => false,
            'serie'        => (int)$s['serie'],
            'ripetizioni'  => (int)$s['ripetizioni'],
            'peso'         => isset($s['peso']) ? (float)$s['peso'] : null,
            'elenco_serie' => [$s], // <-- IMPORTANTE: ora rientra tecnica_intensita
          ]);
        } else {
          // piramidale
          $out[] = array_merge($v, [
            'piramidale'   => true,
            'serie'        => 0,
            'ripetizioni'  => 0,
            'peso'         => null,
            'elenco_serie' => $serieList,
          ]);
        }
      }

      echo json_encode(['success'=>true,'data'=>$out], JSON_UNESCAPED_UNICODE);
      break;
    }


   case 'insert_voce_scheda': {
      $idScheda = (int)($_POST['id_schedat'] ?? 0);
      if (!$idScheda) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'ID_SCHEDAT mancante']); break; }

      if (!$isAdmin) {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      } else {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN ADMIN_CLIENTI ac ON ac.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND ac.ADMIN_UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda non assegnata']); break; }
      }

      $idEsercizio = $_POST['id_esercizio'] ?? null;
      $ordine      = (int)($_POST['ordine'] ?? 0);
      $note        = (isset($_POST['note']) && $_POST['note'] !== '') ? $_POST['note'] : null;
      $superset    = filter_var($_POST['superset'] ?? 'false', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

      // Compatibilità: accetto sia formato compresso che piramidale
      $piramidale  = filter_var($_POST['piramidale'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
      $serie       = (int)($_POST['serie'] ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = isset($_POST['peso']) ? ($_POST['peso'] === '' ? null : $_POST['peso']) : null;
      $elenco      = isset($_POST['elenco_serie']) ? json_decode((string)$_POST['elenco_serie'], true) : [];

      $pdo->beginTransaction();

      // 1) Inserisco la voce (solo campi della DETTA; serie/peso vanno nella tabella figlia)
      $sql = "INSERT INTO SCHEDE_ESERCIZI_DETTA (ID_SCHEDAT, ID_ESERCIZIO, ORDINE, NOTE, SUPERSET)
              VALUES (?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$idScheda, $idEsercizio, $ordine, $note, $superset]);
      $idVoce = (int)$pdo->lastInsertId();

      // 2) Inserisco le serie
      if ($piramidale) {
        if (!is_array($elenco) || count($elenco) === 0) {
          throw new RuntimeException('elenco_serie mancante per piramidale');
        }
        usort($elenco, fn($a,$b)=>($a['serie']??0)<=>($b['serie']??0));
        for ($i=0; $i<count($elenco); $i++) {
          $atteso = $i+1;
          $s = (int)($elenco[$i]['serie'] ?? 0);
          if ($s !== $atteso) throw new RuntimeException("Serie non contigue: atteso $atteso, trovato $s");
        }

        $ins = $pdo->prepare("
          INSERT INTO SCHEDE_ESERCIZI_DETTA_PESO
            (ID_SCHEDAD, SERIE, RIPETIZIONI, PESO, TECNICA_INTENSITA, D_AGG)
          VALUES (:id, :ser, :rip, :peso, :tec, NOW())
        ");

        foreach ($elenco as $r) {
          // compat: se arriva 'note' la consideriamo come tecnica_intensita
          $tec = $r['tecnica_intensita'] ?? $r['note'] ?? null;
          $ins->execute([
            ':id'  => $idVoce,
            ':ser' => (int)$r['serie'],
            ':rip' => (int)$r['ripetizioni'],
            ':peso'=> array_key_exists('peso',$r) ? $r['peso'] : null,
            ':tec' => ($tec !== '' ? $tec : null),
          ]);
        }
      } else {
        if ($serie <= 0 || $ripetizioni <= 0) {
          throw new RuntimeException('Valori non validi: serie/ripetizioni');
        }
        // se nel POST arriva tecnica_intensita (singola), la mettiamo sulla riga "compressa"
        $tecSingola = $_POST['tecnica_intensita'] ?? $_POST['note'] ?? null;

        $ins = $pdo->prepare("
          INSERT INTO SCHEDE_ESERCIZI_DETTA_PESO
            (ID_SCHEDAD, SERIE, RIPETIZIONI, PESO, TECNICA_INTENSITA, D_AGG)
          VALUES (?,?,?,?,?, NOW())
        ");
        $ins->execute([$idVoce, $serie, $ripetizioni, $peso, ($tecSingola !== '' ? $tecSingola : null)]);
      }

      $pdo->commit();
      echo json_encode(['success'=>true,'insertId'=>$idVoce]);
      break;
    }

    case 'update_voce_scheda': {
      $idVoce = (int)($_POST['id_voce'] ?? 0);
      if (!$idVoce) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'ID_SCHEDAD mancante']); break; }

      if (!$isAdmin) {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_DETTA sd
          JOIN SCHEDE_ESERCIZI_TESTA st ON st.ID_SCHEDAT = sd.ID_SCHEDAT
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE sd.ID_SCHEDAD=? AND c.UID=?");
        $chk->execute([$idVoce, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'update_voce_scheda - ID_SCHEDAD not found']); break; }
      }

      $idEsercizio = $_POST['id_esercizio'] ?? null;
      $ordine      = (int)($_POST['ordine'] ?? 0);
      $note        = (isset($_POST['note']) && $_POST['note'] !== '') ? $_POST['note'] : null;
      $superset    = filter_var($_POST['superset'] ?? 'false', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

      $piramidale  = filter_var($_POST['piramidale'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
      $serie       = (int)($_POST['serie'] ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = isset($_POST['peso']) ? ($_POST['peso'] === '' ? null : $_POST['peso']) : null;
      $elenco      = isset($_POST['elenco_serie']) ? json_decode((string)$_POST['elenco_serie'], true) : [];

      $pdo->beginTransaction();

      // 1) Aggiorno voce (campi DETTA)
      $sql = "UPDATE SCHEDE_ESERCIZI_DETTA
              SET ID_ESERCIZIO=?, ORDINE=?, NOTE=?, SUPERSET=?
              WHERE ID_SCHEDAD=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$idEsercizio, $ordine, $note, $superset, $idVoce]);


      // 2) Rimpiazzo le serie
      $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_DETTA_PESO WHERE ID_SCHEDAD=?")->execute([$idVoce]);

      if ($piramidale) {
        if (!is_array($elenco) || count($elenco) === 0) {
          throw new RuntimeException('elenco_serie mancante per piramidale');
        }
        usort($elenco, fn($a,$b)=>($a['serie']??0)<=>($b['serie']??0));
        for ($i=0; $i<count($elenco); $i++) {
          $atteso = $i+1;
          $s = (int)($elenco[$i]['serie'] ?? 0);
          if ($s !== $atteso) throw new RuntimeException("Serie non contigue: atteso $atteso, trovato $s");
        }

        $ins = $pdo->prepare("
          INSERT INTO SCHEDE_ESERCIZI_DETTA_PESO
            (ID_SCHEDAD, SERIE, RIPETIZIONI, PESO, TECNICA_INTENSITA, D_AGG)
          VALUES (:id, :ser, :rip, :peso, :tec, NOW())
        ");
        foreach ($elenco as $r) {
          $tec = $r['tecnica_intensita'] ?? $r['note'] ?? null; // compat
          $ins->execute([
            ':id'  => $idVoce,
            ':ser' => (int)$r['serie'],
            ':rip' => (int)$r['ripetizioni'],
            ':peso'=> array_key_exists('peso',$r) ? $r['peso'] : null,
            ':tec' => ($tec !== '' ? $tec : null),
          ]);
        }
      } else {
        if ($serie <= 0 || $ripetizioni <= 0) {
          throw new RuntimeException('Valori non validi: serie/ripetizioni');
        }
        $tecSingola = $_POST['tecnica_intensita'] ?? $_POST['note'] ?? null;

        $ins = $pdo->prepare("
          INSERT INTO SCHEDE_ESERCIZI_DETTA_PESO
            (ID_SCHEDAD, SERIE, RIPETIZIONI, PESO, TECNICA_INTENSITA, D_AGG)
          VALUES (?,?,?,?,?, NOW())
        ");
        $ins->execute([$idVoce, $serie, $ripetizioni, $peso, ($tecSingola !== '' ? $tecSingola : null)]);
      }

      $pdo->commit();
      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_voce_scheda': {
      $idVoce = (int)($_POST['id_voce'] ?? 0);
      if (!$idVoce) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'ID_SCHEDAD mancante']); break; }

      $chk = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_DETTA sd
        JOIN SCHEDE_ESERCIZI_TESTA st ON st.ID_SCHEDAT = sd.ID_SCHEDAT
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE sd.ID_SCHEDAD=? AND c.UID=?");
      $chk->execute([$idVoce, $uid]);
      if (!$chk->fetch() && !$isAdmin) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Item not found']); break; }

      $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAD=?");
      $stmt->execute([$idVoce]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'get_progress_history': {
      $planId   = $_POST['plan_id']   ?? null;   // SCHEDE_TESTA.ID_SCHEDA
      $clientId = $_POST['client_id'] ?? null;   // CLIENTI.ID
      $from     = $_POST['from']      ?? null;   // 'YYYY-MM-DD' o 'YYYY-MM-DD HH:MM:SS'
      $to       = $_POST['to']        ?? null;

      if (!$planId || !$clientId) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri plan_id e client_id obbligatori']);
        break;
      }

      $sql = "SELECT p.ID_SCHEDAD, p.ID_CLIENTE, p.DONE, p.D_AGG
                FROM SCHEDE_PROGRESS p
                JOIN SCHEDE_ESERCIZI_DETTA d ON d.ID_SCHEDAD = p.ID_SCHEDAD
              WHERE d.ID_SCHEDAD = ? AND p.ID_CLIENTE = ?";
      $params = [(int)$planId, (int)$clientId];

      if ($from) { $sql .= " AND p.D_AGG >= ?"; $params[] = $from; }
      if ($to)   { $sql .= " AND p.D_AGG <= ?"; $params[] = $to;   }

      $sql .= " ORDER BY p.D_AGG ASC, p.ID_SCHEDAD ASC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      echo json_encode(['success'=>true, 'items'=>$rows]);
      break;
    }

    case 'save_progress_session': {
      $planId   = $_POST['plan_id']   ?? null;     // ID scheda testa
      $clientId = $_POST['client_id'] ?? null;     // ID cliente
      $doneJson = $_POST['done_ids']  ?? '[]';     // JSON array di ID_SCHEDAD completati
      $when     = $_POST['when']      ?? null;     // opzionale: 'YYYY-MM-DD HH:MM:SS'
      $doneIds  = json_decode($doneJson, true);

      if (!$planId || !$clientId || !is_array($doneIds)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri plan_id, client_id e done_ids[] obbligatori']);
        break;
      }

      // timestamp sessione coerente per tutte le righe
      if (!$when) {
        // SQLite: current_timestamp; MySQL: NOW()
        $whenStmt = $pdo->query("SELECT NOW()");
        $when = $whenStmt->fetchColumn();
      }

      try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
          INSERT INTO SCHEDE_PROGRESS (ID_SCHEDAD, ID_CLIENTE, DONE, D_AGG)
          SELECT ?, ?, 1, ?
          WHERE EXISTS (
            SELECT 1 FROM SCHEDE_ESERCIZI_DETTA d
            WHERE d.ID_SCHEDAD = ?
          )
        ");

        foreach ($doneIds as $id) {
          $id = (int)$id;
          // la UNIQUE (ID_SCHEDAD, ID_CLIENTE, D_AGG) evita duplicati nella stessa sessione
          $ins->execute([$id, (int)$clientId, $when, $id]);
        }

        $pdo->commit();
        echo json_encode(['success'=>true,'when'=>$when,'count'=>count($doneIds)]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
      }
      break;
    }

    case 'get_plans_last_sessions': {
      $clientId = $_POST['client_id'] ?? null;
      if (!$clientId) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametro client_id obbligatorio']);
        break;
      }

      // 1) Leggo le schede ATTIVE per il cliente, con ultima esecuzione per scheda
      $sql = "SELECT tst.ID_SCHEDAT                               AS plan_id,
                     tst.TIPO_SCHEDA                              AS tipo,
                     tst.DATA_INIZIO                              AS start_date,
                     tst.VALIDITA                                 AS validita,
                     MAX(pro.D_AGG)                               AS last_exec
        FROM SCHEDE_ESERCIZI_TESTA tst
        LEFT JOIN SCHEDE_ESERCIZI_DETTA dtt
              ON dtt.ID_SCHEDAT = tst.ID_SCHEDAT
        LEFT JOIN SCHEDE_PROGRESS pro
              ON pro.ID_SCHEDAD = dtt.ID_SCHEDAD
              AND pro.ID_CLIENTE = ?
        WHERE tst.ID_CLIENTE = ?
          AND tst.DATA_INIZIO <= NOW()
          AND DATE_ADD(tst.DATA_INIZIO, INTERVAL tst.VALIDITA MONTH) >= NOW()
        GROUP BY tst.ID_SCHEDAT, tst.TIPO_SCHEDA, tst.DATA_INIZIO, tst.VALIDITA";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([(int)$clientId, (int)$clientId]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

      // 2) Ordino per TIPO_SCHEDA (A,B,C,...) con fallback su DATA_INIZIO
      usort($rows, function($a, $b) {
        $ta = strtoupper(trim((string)($a['tipo'] ?? '')));
        $tb = strtoupper(trim((string)($b['tipo'] ?? '')));

        $sa = ($ta !== '' && ctype_alpha($ta[0])) ? ord($ta[0]) - ord('A') : 999;
        $sb = ($tb !== '' && ctype_alpha($tb[0])) ? ord($tb[0]) - ord('A') : 999;

        if ($sa !== $sb) return $sa <=> $sb;
        return strcmp((string)$a['start_date'], (string)$b['start_date']);
      });

      // 3) Trovo l’ultima esecuzione globale tra le schede attive
      $lastRow = null;
      foreach ($rows as $r) {
        if (!empty($r['last_exec'])) {
          if ($lastRow === null || (string)$r['last_exec'] > (string)$lastRow['last_exec']) {
            $lastRow = $r;
          }
        }
      }

      // 4) Round-robin: se ho fatto A, propongo B; altrimenti la prima
      $next = null;
      $n = count($rows);
      if ($n === 1) {
        $next = $rows[0];
      } elseif ($n > 1) {
        if ($lastRow === null) {
          // Nessuna esecuzione: inizio dalla prima
          $next = $rows[0];
        } else {
          // Cerco l’indice della scheda usata per ultima
          $idx = -1;
          foreach ($rows as $i => $r) {
            if ((int)$r['plan_id'] === (int)$lastRow['plan_id']) { $idx = $i; break; }
          }
          $next = ($idx === -1) ? $rows[0] : $rows[ ($idx + 1) % $n ];
        }
      }

      echo json_encode(['success'=>true, 'plans'=>$rows, 'next_plan'=>$next]);
      break;
    }

    /* =========================
     *     GRUPPI MUSCOLARI (globali)
     * ========================= */
    case 'get_gruppi_muscolari': {
      $stmt = $pdo->prepare("
        SELECT ID_AGGETTIVO AS code, DESCRIZIONE AS name, IFNULL(COMMENTO,'') AS comment, ORDINE AS ordine
        FROM REFERENZECOMBO_0099
        WHERE ID_CLASSE='GRUPPO_MUSCOLARE'
        ORDER BY ORDINE, DESCRIZIONE");
      $stmt->execute();
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_gruppo_muscolare': {
      $code    = $_POST['code']    ?? null;
      $name    = $_POST['name']    ?? null;
      $comment = $_POST['comment'] ?? null;
      $ordine  = $_POST['ordine']  ?? 999;
      if (!$code || !$name) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Parametri code e name obbligatori']); break; }

      $sql = "INSERT INTO REFERENZECOMBO_0099 (ID_CLASSE, ID_AGGETTIVO, DESCRIZIONE, COMMENTO, ORDINE)
              VALUES ('GRUPPO_MUSCOLARE', ?, ?, ?, ?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$code, $name, ($comment !== '' ? $comment : null), (int)$ordine]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'update_gruppo_muscolare': {
      $code    = $_POST['code']    ?? null;
      $name    = $_POST['name']    ?? null;
      $comment = $_POST['comment'] ?? null;
      if (!$code || !$name) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Parametri code e name obbligatori']); break; }

      $sql = "UPDATE REFERENZECOMBO_0099
              SET DESCRIZIONE=?, COMMENTO=?
              WHERE ID_CLASSE='GRUPPO_MUSCOLARE' AND ID_AGGETTIVO=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$name, ($comment !== '' ? $comment : null), $code]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_gruppo_muscolare': {
      $code = $_POST['code'] ?? null;
      if (!$code) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Parametro code obbligatorio']); break; }

      $chk = $pdo->prepare("SELECT COUNT(*) AS c FROM ESERCIZI WHERE GRUPPO_MUSCOLARE = ?");
      $chk->execute([$code]);
      $row = $chk->fetch();
      if ((int)($row['c'] ?? 0) > 0) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>"Impossibile eliminare: esistono esercizi collegati a '$code'."]);
        break;
      }

      $stmt = $pdo->prepare("DELETE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE='GRUPPO_MUSCOLARE' AND ID_AGGETTIVO=?");
      $stmt->execute([$code]);
      echo json_encode(['success'=>true]);
      break;
    }


    /* =========================
     *      COMUNICAZIONI
     * ========================= */
    case 'get_comunicazioni_attive': {
      $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 5);
      if ($limit <= 0 || $limit > 50) $limit = 5;

      // Solo avvisi abilitati e “on air” nel momento della chiamata
      $sql = "SELECT ID_COMUNICAZIONE, TIPOLOGIA, INIZIO, FINE, TITOLO, TESTO, ABIL, D_AGG
              FROM COMUNICAZIONI
              WHERE ABIL=1
                AND FINE   >  NOW()
              ORDER BY INIZIO DESC
              LIMIT ?";
      $stmt = $pdo->prepare($sql);
      $stmt->bindValue(1, $limit, PDO::PARAM_INT);
      $stmt->execute();

      echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll()]);
      break;
    }

    // Admin: insert
    case 'insert_comunicazione': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $tipologia = trim($_POST['tipologia'] ?? 'GENE');
      $inizio    = trim($_POST['inizio']    ?? '');
      $fine      = trim($_POST['fine']      ?? '');
      $titolo    = trim($_POST['titolo']    ?? '');
      $testo     = trim($_POST['testo']     ?? '');
      $abil      = (int)($_POST['abil']     ?? 1);

      // Validazioni minime
      if ($inizio === '' || $fine === '' || $testo === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri obbligatori: inizio, fine, testo']);
        break;
      }
      // Verifica formato e coerenza (yyyy-mm-dd HH:ii:ss)
      $d1 = DateTime::createFromFormat('Y-m-d H:i:s', $inizio);
      $d2 = DateTime::createFromFormat('Y-m-d H:i:s', $fine);
      if (!$d1 || !$d2 || $d2 <= $d1) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Intervallo data/ora non valido']);
        break;
      }

    $sql = "INSERT INTO COMUNICAZIONI (TIPOLOGIA, INIZIO, FINE, TITOLO, TESTO, ABIL)
            VALUES (?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tipologia, $inizio, $fine, $titolo, $testo, $abil ? 1 : 0]);

      echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
      break;
    }

    // Admin: update
    case 'update_comunicazione': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $id        = (int)($_POST['id'] ?? 0);
      $tipologia = trim($_POST['tipologia'] ?? 'GENE');
      $inizio    = trim($_POST['inizio']    ?? '');
      $fine      = trim($_POST['fine']      ?? '');
      $titolo    = trim($_POST['titolo']    ?? '');
      $testo     = trim($_POST['testo']     ?? '');
      $abil      = (int)($_POST['abil']     ?? 1);

      if ($id <= 0 || $inizio === '' || $fine === '' || $titolo === '' || $testo === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri non validi']);
        break;
      }
      $d1 = DateTime::createFromFormat('Y-m-d H:i:s', $inizio);
      $d2 = DateTime::createFromFormat('Y-m-d H:i:s', $fine);
      if (!$d1 || !$d2 || $d2 <= $d1) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Intervallo data/ora non valido']);
        break;
      }

      $sql = "UPDATE COMUNICAZIONI
              SET TIPOLOGIA=?, INIZIO=?, FINE=?, TITOLO=?, TESTO=?, ABIL=?
              WHERE ID_COMUNICAZIONE=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$tipologia, $inizio, $fine, $titolo, $testo, $abil ? 1 : 0, $id]);

      if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Comunicazione non trovata']);
        break;
      }
      echo json_encode(['success'=>true]);
      break;
    }

    // Admin: delete
    case 'delete_comunicazione': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'id mancante']);
        break;
      }

      $stmt = $pdo->prepare("DELETE FROM COMUNICAZIONI WHERE ID_COMUNICAZIONE=?");
      $stmt->execute([$id]);
      if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Comunicazione non trovata']);
        break;
      }
      echo json_encode(['success'=>true]);
      break;
    }

    /* =========================
     *         DEFAULT
     * ========================= */
    default:
      echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta: ' . $action]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
}