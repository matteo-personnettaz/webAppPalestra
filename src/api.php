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
$needsDb = ($action === 'diag') || !in_array($action, ['ping', 'whoami', 'socketcheck'], true);
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
$isPublic = in_array($action, ['ping', 'whoami', 'socketcheck', 'diag'], true);
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
    $ruolo = (string)($stmt->fetchColumn() ?: '');
    $isAdmin = ($ruolo === 'ADMIN');
  } catch (Throwable $e) {
    $isAdmin = false;
  }
}

/* ===== Funzioni di ownership ===== */
function require_owns_client(PDO $pdo, int $clientId, string $uid): void {
  $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
  $chk->execute([$clientId, $uid]);
  if (!$chk->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Client not found or not owned']);
    exit;
  }
}

/* ===== ROUTING ===== */
try {
  switch ($action) {

    /* =========================
     *  Bootstrap utente (crea/aggiorna riga UTENTI)
     * ========================= */
    case 'bootstrap_user': {
      $email = $_POST['email'] ?? null;
      $ruolo = $_POST['ruolo'] ?? 'CLIENTE'; // default
      $sql = "INSERT INTO UTENTI (UID, EMAIL, RUOLO)
              VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE EMAIL=VALUES(EMAIL), RUOLO=VALUES(RUOLO)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$uid, $email, $ruolo]);

      $stmt = $pdo->prepare("SELECT RUOLO FROM UTENTI WHERE UID=?");
      $stmt->execute([$uid]);
      $isAdmin = ((string)$stmt->fetchColumn() === 'ADMIN');
      echo json_encode(['success' => true, 'is_admin' => $isAdmin]);
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
      $sql = 'INSERT INTO CLIENTI (UID, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
              VALUES (?,?,?,?,?,?,?,?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $uid,
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
      require_owns_client($pdo, $clientId, $uid);

      $sql = 'UPDATE CLIENTI
              SET COGNOME=?,NOME=?,DATA_NASCITA=?,INDIRIZZO=?,CODICE_FISCALE=?,TELEFONO=?,EMAIL=?
              WHERE ID_CLIENTE=? AND UID=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName']   ?? '',
        $_POST['firstName']  ?? '',
        $_POST['birthDate']  ?? date('Y-m-d'),
        $_POST['address']    ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone']      ?? null,
        $_POST['email']      ?? null,
        $clientId,
        $uid,
      ]);
      echo json_encode(['success' => true]);
      break;
    }

    case 'delete_cliente': {
      $clientId = (int)($_POST['id'] ?? 0);
      require_owns_client($pdo, $clientId, $uid);
      $stmt = $pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $stmt->execute([$clientId, $uid]);
      echo json_encode(['success' => true]);
      break;
    }

    /* =========================
     *       MISURAZIONI
     * ========================= */
    case 'get_misurazioni': {
      $cid = (int)($_GET['clientId'] ?? $_POST['clientId'] ?? 0);
      require_owns_client($pdo, $cid, $uid);

      $stmt = $pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE=? ORDER BY DATA_MISURAZIONE DESC');
      $stmt->execute([$cid]);
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    case 'insert_misurazione': {
      $cid = (int)($_POST['clientId'] ?? 0);
      require_owns_client($pdo, $cid, $uid);

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
      require_owns_client($pdo, $cid, $uid);

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
      // ownership: join tramite CLIENTI
      $id = (int)($_POST['id'] ?? 0);
      $chk = $pdo->prepare("
        SELECT 1
        FROM MISURAZIONI m
        JOIN CLIENTI c ON c.ID_CLIENTE = m.ID_CLIENTE
        WHERE m.ID_MISURAZIONE=? AND c.UID=?");
      $chk->execute([$id, $uid]);
      if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Measurement not found']);
        break;
      }
      $stmt = $pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE=?');
      $stmt->execute([$id]);
      echo json_encode(['success'=>true]);
      break;
    }

    /* =========================
     *        ESERCIZI (globali)
     * ========================= */
    case 'get_esercizi': {
      $stmt = $pdo->query('SELECT * FROM ESERCIZI ORDER BY NOME');
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
    case 'get_tipo_appuntamento': {
      $stmt = $pdo->query("SELECT ID_AGGETTIVO AS CODICE, DESCRIZIONE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE='TIPO_APPUNTAMENTO' ORDER BY ORDINE");
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    /* =========================
     *        APPUNTAMENTI
     * ========================= */
    case 'get_appuntamenti': {
      if ($isAdmin) {
        $stmt = $pdo->query("SELECT a.ID_APPUNTAMENTO,a.ID_CLIENTE,a.DATA_ORA,a.TIPOLOGIA,a.NOTE,a.STATO,a.ID_SLOT,
                                    c.NOME,c.COGNOME
                             FROM APPUNTAMENTI a
                             JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
                             ORDER BY a.DATA_ORA ASC");
      } else {
        $stmt = $pdo->prepare("SELECT a.ID_APPUNTAMENTO,a.ID_CLIENTE,a.DATA_ORA,a.TIPOLOGIA,a.NOTE,a.STATO,a.ID_SLOT,
                                      c.NOME,c.COGNOME
                               FROM APPUNTAMENTI a
                               JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
                               WHERE c.UID=?
                               ORDER BY a.DATA_ORA ASC");
        $stmt->execute([$uid]);
      }
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      break;
    }

    // (Nota: in flusso attuale gli utenti NON creano manualmente appuntamenti senza slot)
    case 'insert_appuntamento': {
      $cid = (int)($_POST['clientId'] ?? 0);
      require_owns_client($pdo, $cid, $uid);

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
      require_owns_client($pdo, $cid, $uid);

      // ownership del record da aggiornare (tramite join)
      $idApp = (int)($_POST['id'] ?? 0);
      $chk = $pdo->prepare("SELECT 1
                            FROM APPUNTAMENTI a
                            JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
                            WHERE a.ID_APPUNTAMENTO=? AND c.UID=?");
      $chk->execute([$idApp, $uid]);
      if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Appointment not found']);
        break;
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

    case 'delete_appuntamento': {
      $idApp = (int)($_POST['id'] ?? 0);
      // ownership
      $chk = $pdo->prepare("SELECT 1
                            FROM APPUNTAMENTI a
                            JOIN CLIENTI c ON c.ID_CLIENTE=a.ID_CLIENTE
                            WHERE a.ID_APPUNTAMENTO=? AND c.UID=?");
      $chk->execute([$idApp, $uid]);
      if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Appointment not found']);
        break;
      }
      $stmt = $pdo->prepare("DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$idApp]);
      echo json_encode(['success' => true]);
      break;
    }

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

    case 'prenota_slot': {
      $idSlot    = (int)($_POST['id_slot'] ?? 0);
      $idCliente = (int)($_POST['id_cliente'] ?? 0);
      $note      = $_POST['note'] ?? null;

      if (!$idSlot || !$idCliente) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'id_slot e id_cliente obbligatori']);
        break;
      }

      // ownership cliente
      require_owns_client($pdo, $idCliente, $uid);

      try {
        $pdo->beginTransaction();

        // lock & check slot
        $q = $pdo->prepare("SELECT TIPOLOGIA, INIZIO, OCCUPATO FROM FASCE_APPUNTAMENTO WHERE ID_SLOT=? FOR UPDATE");
        $q->execute([$idSlot]);
        $s = $q->fetch();
        if (!$s) {
          $pdo->rollBack();
          http_response_code(404);
          echo json_encode(['success'=>false,'error'=>'Fascia non trovata']);
          break;
        }
        if ((int)$s['OCCUPATO'] === 1) {
          $pdo->rollBack();
          http_response_code(409);
          echo json_encode(['success'=>false,'error'=>'Fascia già occupata']);
          break;
        }

        // crea appuntamento pendente legato allo slot (NO UID nello schema)
        $ins = $pdo->prepare("INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE, STATO, ID_SLOT)
                              VALUES (?,?,?,?,0,?)");
        $ins->execute([$idCliente, $s['INIZIO'], $s['TIPOLOGIA'], ($note !== '' ? $note : null), $idSlot]);

        // marca fascia occupata
        $pdo->prepare("UPDATE FASCE_APPUNTAMENTO SET OCCUPATO=1 WHERE ID_SLOT=?")->execute([$idSlot]);

        $pdo->commit();
        echo json_encode(['success'=>true,'id_appuntamento'=>$pdo->lastInsertId()]);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Errore prenotazione','details'=>$e->getMessage()]);
      }
      break;
    }

    case 'conferma_appuntamento': {
      if (!$isAdmin) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Solo admin']); break; }
      $idApp = (int)($_POST['id_appuntamento'] ?? 0);
      if (!$idApp) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_appuntamento obbligatorio']); break; }

      $stmt = $pdo->prepare("UPDATE APPUNTAMENTI SET STATO=1 WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$idApp]);
      if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Appuntamento non trovato']); break; }

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
          $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE ID_CLIENTE=? ORDER BY ID_SCHEDAT DESC");
          $stmt->execute([$clientId]);
        } else {
          $stmt = $pdo->query("SELECT * FROM SCHEDE_ESERCIZI_TESTA ORDER BY ID_SCHEDAT DESC");
        }
      } else {
        if ($clientId) {
          require_owns_client($pdo, $clientId, $uid);
          $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE ID_CLIENTE=? ORDER BY ID_SCHEDAT DESC");
          $stmt->execute([$clientId]);
        } else {
          // tutte le schede dei tuoi clienti
          $stmt = $pdo->prepare("
            SELECT st.*
            FROM SCHEDE_ESERCIZI_TESTA st
            JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
            WHERE c.UID=?
            ORDER BY st.ID_SCHEDAT DESC");
          $stmt->execute([$uid]);
        }
      }
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_scheda_testa': {
      $clientId = (int)($_POST['clientId'] ?? 0);
      require_owns_client($pdo, $clientId, $uid);

      $sql = "INSERT INTO SCHEDE_ESERCIZI_TESTA
              (ID_CLIENTE, DATA_INIZIO, NUM_SETIMANE, GIORNI_A_SET, NOTE)
              VALUES (?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $clientId,
        $_POST['dataInizio']    ?? date('Y-m-d'),
        $_POST['numSettimane']  ?? 3,
        $_POST['giorniASet']    ?? 5,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;
    }

    case 'update_scheda_testa': {
      $clientId = (int)($_POST['clientId'] ?? 0);
      require_owns_client($pdo, $clientId, $uid);

      $idSchedat = (int)($_POST['id_schedat'] ?? 0);
      // ownership della scheda (tramite cliente)
      $chk = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_TESTA st
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE st.ID_SCHEDAT=? AND c.UID=?");
      $chk->execute([$idSchedat, $uid]);
      if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'Plan not found']);
        break;
      }

      $sql = "UPDATE SCHEDE_ESERCIZI_TESTA
              SET ID_CLIENTE=?, DATA_INIZIO=?, NUM_SETIMANE=?, GIORNI_A_SET=?, NOTE=?
              WHERE ID_SCHEDAT=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $clientId,
        $_POST['dataInizio']    ?? date('Y-m-d'),
        $_POST['numSettimane']  ?? 3,
        $_POST['giorniASet']    ?? 5,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        $idSchedat,
      ]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_scheda_testa': {
      $id = (int)($_POST['id'] ?? 0);
      // ownership: scheda del mio cliente?
      $chk = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_TESTA st
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE st.ID_SCHEDAT=? AND c.UID=?");
      $chk->execute([$id, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Plan not found']); break; }

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
      if (!$idScheda) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_scheda mancante']); break; }

      if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAT=? ORDER BY SETTIMANA, GIORNO, ORDINE, ID_SCHEDAD");
        $stmt->execute([$idScheda]);
      } else {
        $own = $pdo->prepare("
          SELECT 1
          FROM SCHEDE_ESERCIZI_TESTA st
          JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
          WHERE st.ID_SCHEDAT=? AND c.UID=?");
        $own->execute([$idScheda, $uid]);
        if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Plan not found']); break; }

        $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAT=? ORDER BY SETTIMANA, GIORNO, ORDINE, ID_SCHEDAD");
        $stmt->execute([$idScheda]);
      }
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;
    }

    case 'insert_voce_scheda': {
      $idScheda = (int)($_POST['id_schedat'] ?? 0);
      $own = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_TESTA st
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE st.ID_SCHEDAT=? AND c.UID=?");
      $own->execute([$idScheda, $uid]);
      if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Plan not found']); break; }

      $serie       = (int)($_POST['serie']       ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso']        ?? 0);
      $rest        = (int)($_POST['rest']        ?? 0);
      $ordine      = (int)($_POST['ordine']      ?? 0);

      $sql = "INSERT INTO SCHEDE_ESERCIZI_DETTA
              (ID_SCHEDAT, ID_ESERCIZIO, SETTIMANA, GIORNO, SERIE, RIPETIZIONI, PESO, REST, ORDINE, NOTE)
              VALUES (?,?,?,?,?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $idScheda,
        $_POST['id_esercizio'] ?? null,
        $_POST['settimana']    ?? 1,
        $_POST['giorno']       ?? 1,
        $serie,
        $ripetizioni,
        $peso,
        $rest,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;
    }

    case 'update_voce_scheda': {
      $idVoce   = (int)($_POST['id_voce'] ?? 0);
      // ownership voce via scheda → join
      $chk = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_DETTA sd
        JOIN SCHEDE_ESERCIZI_TESTA st ON st.ID_SCHEDAT = sd.ID_SCHEDAT
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE sd.ID_SCHEDAD=? AND c.UID=?");
      $chk->execute([$idVoce, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Item not found']); break; }

      $serie       = (int)($_POST['serie']       ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso']        ?? 0);
      $rest        = (int)($_POST['rest']        ?? 0);
      $ordine      = (int)($_POST['ordine']      ?? 0);

      $sql = "UPDATE SCHEDE_ESERCIZI_DETTA
              SET ID_ESERCIZIO=?, SETTIMANA=?, GIORNO=?, SERIE=?, RIPETIZIONI=?, PESO=?, REST=?, ORDINE=?, NOTE=?
              WHERE ID_SCHEDAD=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['id_esercizio'] ?? null,
        $_POST['settimana']    ?? 1,
        $_POST['giorno']       ?? 1,
        $serie,
        $ripetizioni,
        $peso,
        $rest,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        $idVoce,
      ]);
      echo json_encode(['success'=>true]);
      break;
    }

    case 'delete_voce_scheda': {
      $idVoce = (int)($_POST['id_voce'] ?? 0);
      // ownership via join
      $chk = $pdo->prepare("
        SELECT 1
        FROM SCHEDE_ESERCIZI_DETTA sd
        JOIN SCHEDE_ESERCIZI_TESTA st ON st.ID_SCHEDAT = sd.ID_SCHEDAT
        JOIN CLIENTI c ON c.ID_CLIENTE = st.ID_CLIENTE
        WHERE sd.ID_SCHEDAD=? AND c.UID=?");
      $chk->execute([$idVoce, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Item not found']); break; }

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
     *         DEFAULT
     * ========================= */
    default:
      echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta: ' . $action]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
}
