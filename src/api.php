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

// === Email di benvenuto/reset con password temporanea
// function email_temp_password(string $to, string $displayName, string $tempPassword): array {
//   $appName = getenv('APP_NAME') ?: 'Palestra Athena';
//   $loginUrl = getenv('APP_LOGIN_URL') ?: 'https://palestra-athena.web.app';
//   $html = '
//     <p>Gentile '.htmlspecialchars($displayName ?: $to).',</p>
//     <p>Di seguito l\'accesso a <b>'.$appName.'</b>.</p>
//     <div><p>Password temporanea: <b style="font-family:monospace;">'.htmlspecialchars($tempPassword).'</b></p></div>
//     <p>Per motivi di sicurezza ti consigliamo di cambiarla al primo accesso.</p>'.
//     ($loginUrl ? '<p>Accedi da qui: <a href="'.htmlspecialchars($loginUrl).'">'.$loginUrl.'</a></p>' : '').
//     '<p>Se non hai richiesto questo accesso contatta l\'amministratore.</p>';
//   return sendEmail([
//     'to'      => $to,
//     'subject' => 'Accesso WebApp Palestra Athena - Password Temporanea',
//     'html'    => $html,
//   ]);
// }

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
  $rows      = $args['rows']      ?? [];   // es. [['label'=>'Email','value'=>'...'], ['label'=>'Password','value'=>'...']]
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
 *  (Opzionale) Allinea anche il reset password
 *  alla nuova grafica, riusando il template
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
$needsDb = ($action === 'diag') || !in_array($action, ['ping', 'whoami', 'socketcheck', 'email_test'], true);

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
$isPublic = in_array($action, ['ping', 'whoami', 'socketcheck', 'diag', 'email_test','provision_if_allowed'], true);

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
// function require_can_access_client(PDO $pdo, int $clientId, string $uid): void {
//   $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
//   $chk->execute([$clientId, $uid]);
//   if (!$chk->fetch()) {
//     http_response_code(404);
//     echo json_encode(['success' => false, 'error' => 'require_can_access_client - ID_CLIENTE not found or not owned']);
//     exit;
//   }
// }

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
 * Consente:
 *  - admin su QUALSIASI cliente (anche con UID NULL)
 *  - utente proprietario (CLIENTI.UID = $uid)
 */
function require_can_access_client(PDO $pdo, int $cid, string $uid): void {
  if ($cid <= 0) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'ID_CLIENTE non valido']);
    exit;
  }

  if (is_admin($pdo, $uid)) {
    require_client_exists($pdo, $cid);
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

      // Se esiste già, aggiorno solo email (e display_name se la colonna esiste),
      // SENZA toccare RUOLO. Se non esiste, lo creo come CLIENTE (o ADMIN se whitelisted).
      // Nota: se non hai la colonna DISPLAY_NAME, rimuovi dal SQL i riferimenti a DISPLAY_NAME.
      $sql = "INSERT INTO UTENTI (UID, EMAIL, RUOLO)
              VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$uid, $email, $shouldBeAdmin ? 'ADMIN' : 'CLIENTE']);

      // Rileggi il ruolo effettivo (non declassare eventuali admin esistenti)
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
    case 'admin_create_client': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      // Dati cliente
      $lastName   = trim($_POST['lastName']   ?? '');
      $firstName  = trim($_POST['firstName']  ?? '');
      $birthDate  = $_POST['birthDate']       ?? date('Y-m-d');
      $address    = ($_POST['address']    ?? '') ?: null;
      $fiscalCode = ($_POST['fiscalCode'] ?? '') ?: null;
      $phone      = ($_POST['phone']      ?? '') ?: null;
      $email      = trim($_POST['email']  ?? '');

      if ($lastName === '' || $firstName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametri obbligatori mancanti o email non valida']);
        break;
      }

      // Se esiste già in UTENTI con ruolo ADMIN → blocca (gli admin non devono avere riga in CLIENTI)
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
          // utente esiste in UTENTI, prova a prenderlo anche in Firebase
          try {
            $fu = $auth->getUserByEmail($email);
            $uidNew = $fu->uid;
            // imposta sempre password temporanea e displayName
            $auth->updateUser($uidNew, [
              'password'     => $tempPass,
              'displayName'  => trim("$firstName $lastName"),
              'disabled'     => false,
            ]);
          } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            // in casi rari: riga UTENTI senza utente Firebase → crealo
            $created = $auth->createUser([
              'email'        => $email,
              'password'     => $tempPass,
              'displayName'  => trim("$firstName $lastName"),
              'emailVerified'=> false,
              'disabled'     => false,
            ]);
            $uidNew = $created->uid;
          }
        } else {
          // non presente in UTENTI → crea direttamente in Firebase
          try {
            $fu = $auth->getUserByEmail($email);
            $uidNew = $fu->uid;
            $auth->updateUser($uidNew, [
              'password'     => $tempPass,
              'displayName'  => trim("$firstName $lastName"),
              'disabled'     => false,
            ]);
          } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
            $created = $auth->createUser([
              'email'        => $email,
              'password'     => $tempPass,
              'displayName'  => trim("$firstName $lastName"),
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

        // Upsert UTENTI come CLIENTE (mai declassare un ADMIN)
        $pdo->prepare("
          INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
          VALUES (?,?, 'CLIENTE', 1)
          ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL)
        ")->execute([$uidNew, $email]);

        // Inserisci CLIENTI (EMAIL e CF univoci)
        $stmt = $pdo->prepare('
          INSERT INTO CLIENTI
            (UID, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
          VALUES (?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
          $uidNew, $lastName, $firstName, $birthDate, $address, $fiscalCode, $phone, $email
        ]);

        $clientId = (int)$pdo->lastInsertId();
        $pdo->commit();

        // 3) Email di benvenuto con password temporanea
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

      // Deve esistere almeno un cliente con questa email.
      // Cerco anche nome/cognome (supporto sia NOME/COGNOME sia FIRST_NAME/LAST_NAME).
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

      // Ricava un display name sensato
      $first = trim($row['NOME'] ?? ($row['FIRST_NAME'] ?? ''));
      $last  = trim($row['COGNOME'] ?? ($row['LAST_NAME'] ?? ''));
      $fullName = trim("$first $last");

      // Carica Admin SDK qui (siamo in rotta "pubblica")
      $auth = require __DIR__ . '/firebase_admin.php';

      // 1) Trova o crea utente Firebase
      try {
        $fu = $auth->getUserByEmail($email);
        $uidNew = $fu->uid;

        // Se ho un nome e quello in Auth è diverso/vuoto, aggiornalo
        if ($fullName !== '' && $fu->displayName !== $fullName) {
          $auth->updateUser($uidNew, ['displayName' => $fullName]);
        }
      } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
        // Crea utente con password temporanea (l'utente poi la reimposta)
        $tempPass = generate_temp_password(12);
        $data = [
          'email'         => $email,
          'password'      => $tempPass,
          'emailVerified' => false,
          'disabled'      => false,
        ];
        if ($fullName !== '') {
          $data['displayName'] = $fullName;
        }
        $created = $auth->createUser($data);
        $uidNew  = $created->uid;
      }

      // 2) Upsert in UTENTI come CLIENTE
      $pdo->prepare("
        INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
        VALUES (?,?, 'CLIENTE', 1)
        ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL), ATTIVO=1
      ")->execute([$uidNew, $email]);

      // 3) Collega tutti i CLIENTI con quell'email (solo dove UID è NULL/vuoto)
      $pdo->prepare("
        UPDATE CLIENTI
        SET UID = ?
        WHERE EMAIL = ?
          AND (UID IS NULL OR UID = '')
      ")->execute([$uidNew, $email]);

      echo json_encode([
        'success' => true,
        'uid'     => $uidNew,
        'displayName' => $fullName, // utile al client (facoltativo)
      ]);
      break;
    }

    /* =========================
    *  ADMIN: invia "benvenuto" (credenziali) a CLIENTE
    * ========================= */
    case 'admin_send_welcome': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $clientId = (int)($_POST['clientId'] ?? 0);
      if (!$clientId) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'clientId obbligatorio']); break; }

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

      // Genera nuova password temporanea e aggiornala su Firebase
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

      // Email "benvenuto" con credenziali
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

      // Email "reset password"
      $mailRes = email_temp_password($email, $fullName, $tempPass);
      echo json_encode(['success'=>($mailRes['ok'] ?? false) === true, 'details'=>$mailRes]);
      break;
    }

    /* =========================
    *  ADMIN: crea UTENTE ADMIN (solo UTENTI + Firebase, nessuna riga CLIENTI)
    * ========================= */
    case 'admin_create_admin': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }

      $email = trim($_POST['email'] ?? '');
      $name  = trim($_POST['displayName'] ?? '');

      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Email o displayName non validi']);
        break;
      }

      // Non consentire se esiste già come CLIENTE
      $chkCli = $pdo->prepare("SELECT 1 FROM CLIENTI WHERE EMAIL=? LIMIT 1");
      $chkCli->execute([$email]);
      if ($chkCli->fetch()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'Esiste già un CLIENTE con questa email']);
        break;
      }

      $tempPass = generate_temp_password(12);
      try {
        // Crea/aggiorna in Firebase
        try {
          $fu    = $auth->getUserByEmail($email);
          $uid   = $fu->uid;
          $auth->updateUser($uid, [
            'password'    => $tempPass,
            'displayName' => $name,
            'disabled'    => false,
          ]);
        } catch (\Kreait\Firebase\Exception\Auth\UserNotFound $e) {
          $created = $auth->createUser([
            'email'        => $email,
            'password'     => $tempPass,
            'displayName'  => $name,
            'emailVerified'=> false,
            'disabled'     => false,
          ]);
          $uid = $created->uid;
        }

        // Upsert in UTENTI come ADMIN
        $pdo->prepare("
          INSERT INTO UTENTI (UID, EMAIL, RUOLO, ATTIVO)
          VALUES (?,?, 'ADMIN', 1)
          ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL), RUOLO='ADMIN', ATTIVO=1
        ")->execute([$uid, $email]);

      } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Creazione admin fallita: '.$e->getMessage()]);
        break;
      }

      // Invia password temporanea
      $mailRes = email_temp_password($email, $name, $tempPass);
      echo json_encode(['success'=>($mailRes['ok'] ?? false) === true, 'uid'=>$uid, 'details'=>$mailRes]);
      break;
    }


    /* =========================
     *        CLIENTI
     * ========================= */
    case 'get_clienti': {
      if ($isAdmin) {
        $stmt = $pdo->query('SELECT ID_CLIENTE, UID, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL
                             FROM CLIENTI
                             ORDER BY COGNOME, NOME');
      } else {
        $stmt = $pdo->prepare('SELECT ID_CLIENTE, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL
                               FROM CLIENTI WHERE UID=? ORDER BY COGNOME, NOME');
        $stmt->execute([$uid]);
      }
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_cliente': {
      $sql = 'INSERT INTO CLIENTI (COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
              VALUES (?, ?, ?, ?, ?, ?, ?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName']   ?? '',
        $_POST['firstName']  ?? '',
        $_POST['birthDate']  ?? date('Y-m-d'),
        $_POST['address']    ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone']      ?? null,
        $_POST['email']      ?? null,
      ]);
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      break;
    }

    case 'update_cliente': {
      $clientId = (int)($_POST['id'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $clientId, $uid); }
      else           { require_client_exists($pdo, $clientId); }

      $sql = $isAdmin
        ? 'UPDATE CLIENTI
          SET COGNOME=?,NOME=?,DATA_NASCITA=?,INDIRIZZO=?,CODICE_FISCALE=?,TELEFONO=?,EMAIL=?
          WHERE ID_CLIENTE=?'
        : 'UPDATE CLIENTI
          SET COGNOME=?,NOME=?,DATA_NASCITA=?,INDIRIZZO=?,CODICE_FISCALE=?,TELEFONO=?,EMAIL=?
          WHERE ID_CLIENTE=? AND UID=?';

      $params = [
        $_POST['lastName']   ?? '',
        $_POST['firstName']  ?? '',
        $_POST['birthDate']  ?? date('Y-m-d'),
        $_POST['address']    ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone']      ?? null,
        $_POST['email']      ?? null,
        $clientId,
      ];
      if (!$isAdmin) $params[] = $uid;

      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      echo json_encode(['success' => true]);
      break;
    }

    case 'delete_cliente': {
      $clientId = (int)($_POST['id'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $clientId, $uid); }
      else           { require_client_exists($pdo, $clientId); }

      $sql = $isAdmin
        ? 'DELETE FROM CLIENTI WHERE ID_CLIENTE=?'
        : 'DELETE FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?';

      $stmt = $pdo->prepare($sql);
      $stmt->execute($isAdmin ? [$clientId] : [$clientId, $uid]);
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
     *   TIPI APPUNTAMENTO (globali)
     * ========================= */
    // case 'get_tipo_appuntamento': {
    //   $stmt = $pdo->query("SELECT ID_AGGETTIVO AS CODICE, DESCRIZIONE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE='TIPO_APPUNTAMENTO' ORDER BY ORDINE");
    //   echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    //   break;
    // }

    /* =========================
     *        APPUNTAMENTI
     * ========================= */
    case 'get_appuntamenti':
      if ($isAdmin) {
        $stmt = $pdo->query("SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, a.STATO, a.ID_SLOT,
                                    c.NOME, c.COGNOME
                              FROM APPUNTAMENTI a
                              JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
                              ORDER BY a.DATA_ORA ASC
                              ");
      } else {
        // NOTA: rimuoviamo il filtro a.UID=? e usiamo SOLO la proprietà del cliente
        $stmt = $pdo->prepare("SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, a.STATO, a.ID_SLOT,
                                      c.NOME, c.COGNOME
                                FROM APPUNTAMENTI a
                                JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
                                WHERE c.UID=?
                                ORDER BY a.DATA_ORA ASC");
        $stmt->execute([$uid]);
      }
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;

    // (Nota: in flusso attuale gli utenti NON creano manualmente appuntamenti senza slot)
    case 'insert_appuntamento': {
      $cid = (int)($_POST['clientId'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $cid, $uid); }

      $stmt = $pdo->prepare("INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE)
                             VALUES (?,?,?,?)");
      $stmt->execute([
        $cid,
        $_POST['datetime'] ?? date('Y-m-d H:i:s'),
        $_POST['typeCode'] ?? 'GENE',
        $_POST['note']     ?: null,
      ]);
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      break;
    }

    case 'update_appuntamento': {
      $cid = (int)($_POST['clientId'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $cid, $uid); }

      $idApp = (int)($_POST['id'] ?? 0);
      if (!$isAdmin) {
        $chk = $pdo->prepare("SELECT 1
                              FROM APPUNTAMENTI a
                              JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
                              WHERE a.ID_APPUNTAMENTO=? AND c.UID=?");
        $chk->execute([$idApp, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appointment not found']); break; }
      } else {
        // opzionale: verifica che esista comunque
        $chk = $pdo->prepare("SELECT 1 FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=?");
        $chk->execute([$idApp]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appointment not found']); break; }
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
      if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Solo admin']);
        break;
      }
      $id = (int)($_POST['id'] ?? 0);
      $note = ($_POST['note'] ?? '');
      $note = ($note === '') ? null : $note;

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
      $id = (int)($_POST['id'] ?? 0); // <-- il client manda 'id'
      if (!$id) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Parametro id mancante']);
        break;
      }

      try {
        // 1) Trova appuntamento + UID proprietario + slot
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

        // 2) Se non admin, verifica che l'appuntamento appartenga al tuo UID
        if (!$isAdmin && $row['UID'] !== $uid) {
          http_response_code(403);
          echo json_encode(['success'=>false,'error'=>'Forbidden']);
          break;
        }

        // 3) Cancella in transazione e libera la fascia
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

      // 1) Recupera UID del cliente (serve solo per i controlli, NON si salva su APPUNTAMENTI)
      $own = $pdo->prepare("SELECT UID FROM CLIENTI WHERE ID_CLIENTE=?");
      $own->execute([$idCliente]);
      $rowCli = $own->fetch();
      if (!$rowCli) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Cliente non trovato']);
        break;
      }
      $clienteUid = (string)$rowCli['UID'];

      // Se NON admin, il cliente deve appartenere all’utente loggato
      if (!$isAdmin && $clienteUid !== $uid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Cliente non appartenente all’utente']);
        break;
      }

      try {
        $pdo->beginTransaction();

        // 2) Blocca lo slot e verifica che sia libero
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

        // 3) Evita doppioni: stesso cliente, stessa data/ora pendente/confermato
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

        // 4) stato: admin -> confermato (1), altrimenti pendente (0)
        $stato = $isAdmin ? 1 : 0;

        // 5) insert senza UID
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

        // 6) Marca fascia occupata
        $pdo->prepare("UPDATE FASCE_APPUNTAMENTO SET OCCUPATO=1 WHERE ID_SLOT=?")->execute([$idSlot]);

        $pdo->commit();

        // Notifica admin solo se è un cliente a prenotare
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
          $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE ID_CLIENTE=? ORDER BY DATA_INIZIO DESC, TIPO_SCHEDA ASC, ABIL DESC");
          $stmt->execute([$clientId]);
        } else {
          $stmt = $pdo->query("SELECT * FROM SCHEDE_ESERCIZI_TESTA ORDER BY ID_CLIENTE ASC,DATA_INIZIO DESC, TIPO_SCHEDA ASC, ABIL DESC");
        }
      } else {
        if ($clientId) {
          require_can_access_client($pdo, $clientId, $uid);
          $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE ID_CLIENTE=? ORDER BY DATA_INIZIO DESC, TIPO_SCHEDA ASC, ABIL DESC");
          $stmt->execute([$clientId]);
        } else {
          $stmt = $pdo->prepare("
            SELECT st.*
            FROM SCHEDE_ESERCIZI_TESTA st
            JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
            WHERE c.UID=?
            ORDER BY st.DATA_INIZIO DESC, st.TIPO_SCHEDA ASC, st.ABIL DESC");
          $stmt->execute([$uid]);
        }
      }
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_scheda_testa': {
      $clientId = (int)($_POST['clientId'] ?? 0);
      if (!$isAdmin) { require_can_access_client($pdo, $clientId, $uid); }

      $tipoScheda = $_POST['tipoScheda'] ?? null; // 'A','B','C'
      $validita   = (int)($_POST['validita'] ?? 2); // mesi
      $dataInizio = $_POST['dataInizio'] ?? date('Y-m-d');
      $note       = ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null;
      $abil       = (int)($_POST['abil'] ?? 1);

      $sql = "INSERT INTO SCHEDE_ESERCIZI_TESTA
              (ID_CLIENTE, TIPO_SCHEDA, VALIDITA, DATA_INIZIO, NOTE, ABIL)
              VALUES (?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$clientId, $tipoScheda, $validita, $dataInizio, $note, $abil]);

      // Recupera email cliente
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

      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;
    }

    case 'update_scheda_testa': {
      $clientId   = (int)($_POST['clientId'] ?? 0);
      $idSchedat  = (int)($_POST['id_schedat'] ?? 0);

      // ownership della scheda (tramite cliente)
      if (!$isAdmin) {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $chk->execute([$idSchedat, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
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

      // ownership check
      if (!$isAdmin) {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      }

      $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAT=? ORDER BY ORDINE");
      $stmt->execute([$idScheda]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_voce_scheda': {
      $idScheda = (int)($_POST['id_schedat'] ?? 0);
      // ownership check
      if (!$isAdmin) {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Scheda Testa or UID not found']); break; }
      }

      $serie       = (int)($_POST['serie']       ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso']      ?? 0);
      $ordine      = (int)($_POST['ordine']      ?? 0);

      $sql = "INSERT INTO SCHEDE_ESERCIZI_DETTA
              (ID_SCHEDAT, ID_ESERCIZIO, SERIE, RIPETIZIONI, PESO, ORDINE, NOTE)
              VALUES (?,?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $idScheda,
        $_POST['id_esercizio'] ?? null,
        $serie,
        $ripetizioni,
        $peso,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;
    }

    case 'update_voce_scheda': {
      $idVoce   = (int)($_POST['id_voce'] ?? 0);
      if (!$isAdmin) {
        $chk = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_DETTA sd
          JOIN SCHEDE_ESERCIZI_TESTA st ON st.ID_SCHEDAT = sd.ID_SCHEDAT
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE sd.ID_SCHEDAD=? AND c.UID=?");
        $chk->execute([$idVoce, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'update_voce_scheda - ID_SCHEDAD or  not found']); break; }
      }

      $serie       = (int)($_POST['serie']       ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso']      ?? 0);
      $ordine      = (int)($_POST['ordine']      ?? 0);

      $sql = "UPDATE SCHEDE_ESERCIZI_DETTA
              SET ID_ESERCIZIO=?, SERIE=?, RIPETIZIONI=?, PESO=?, ORDINE=?, NOTE=?
              WHERE ID_SCHEDAD=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['id_esercizio'] ?? null,
        $serie,
        $ripetizioni,
        $peso,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        $idVoce,
      ]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_voce_scheda': {
      $idVoce = (int)($_POST['id_voce'] ?? 0);
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

      $sql = "INSERT INTO COMUNICAZIONI (TIPOLOGIA, INIZIO, FINE, TESTO, ABIL)
              VALUES (?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$tipologia, $inizio, $fine, $testo, $abil ? 1 : 0]);

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