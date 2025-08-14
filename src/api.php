<?php
declare(strict_types=1);

/* ===== CORS & JSON ===== */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

/* ===== Composer & Firebase Auth ===== */
require __DIR__ . '/vendor/autoload.php';
$auth = require __DIR__ . '/firebase_admin.php'; // ritorna un'istanza di Kreait\Auth pronta

/* ===== Helper: verifica token e restituisce UID ===== */
function require_uid($auth): string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Missing bearer token']); exit;
  }
  try {
    $verified = $auth->verifyIdToken($m[1]);
    return (string)$verified->claims()->get('sub');
  } catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Invalid token: '.$e->getMessage()]); exit;
  }
}

/* ===== API KEY (se ti serve ancora per compatibilità) ===== */
$API_KEY = getenv('API_KEY') ?: 'override_me_in_prod';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$key    = $_GET['key']    ?? $_POST['key']    ?? '';

/* ===== ENV CONFIG ===== */
$CFG = [
  'DB_NAME'   => getenv('DB_NAME')   ?: 'fitness_db',
  'DB_USER'   => getenv('DB_USER')   ?: '',
  'DB_PASS'   => getenv('DB_PASS')   ?: '',
  'DB_SOCKET' => getenv('DB_SOCKET') ?: '',
  'DB_HOST'   => getenv('DB_HOST')   ?: '',
  'DB_PORT'   => (int)(getenv('DB_PORT') ?: 3306),
];

/* ===== ROUTE: ping / whoami / socketcheck (no DB) ===== */
if ($action === 'ping') {
  echo json_encode(['success'=>true,'message'=>'pong']); exit;
}
if ($action === 'whoami') {
  echo json_encode([
    'success'    => true,
    'rev'        => getenv('K_REVISION') ?: 'n/a',
    'has_socket' => $CFG['DB_SOCKET'] ?: 'n/a',
  ]); exit;
}
if ($action === 'socketcheck') {
  $sock = $CFG['DB_SOCKET'];
  echo json_encode([
    'success'     => true,
    'dir_exists'  => is_dir('/cloudsql'),
    'sock'        => $sock,
    'sock_exists' => $sock ? file_exists($sock) : null,
  ]); exit;
}

/* ===== Auth per le rotte che toccano il DB ===== */
if (!in_array($action, ['ping','whoami','socketcheck','diag'], true)) {
  if ($key !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Chiave API non valida']); exit;
  }
  $uid = require_uid($auth);
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
    'success'=>false,
    'error'=>'Connessione DB fallita',
    'details'=>['dsn_tried'=>$tried, 'last_error'=>$last ?? 'n/a', 'hint'=>'Impostare DB_SOCKET oppure DB_HOST/DB_PORT'],
  ]);
  exit;
}

/* ===== DB CONNECT ===== */
[$pdo, $connKind, $dsnTried] = connect_pdo($CFG);

/* ===== DIAG (con DB) ===== */
if ($action === 'diag') {
  try {
    $meta = $pdo->query("SELECT NOW() AS now_ts, CURRENT_USER() AS cur_user, USER() AS user_func, DATABASE() AS db, VERSION() AS ver")->fetch();
    $ssl  = $pdo->query("SHOW VARIABLES LIKE 'require_secure_transport'")->fetch();
    $cnt  = $pdo->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE()")->fetch();
    echo json_encode([
      'success'=>true,
      'connection'=>['kind'=>$connKind, 'dsn_tried'=>$dsnTried],
      'meta'=>$meta,
      'require_secure_transport'=>$ssl['Value'] ?? null,
      'tables_count'=>(int)($cnt['c'] ?? 0),
    ]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage(),'dsn_tried'=>$dsnTried]);
  }
  exit;
}

/* ===== ROUTING ===== */
try {
  switch ($action) {

/* =========================
 *        CLIENTI (UID)
 * ========================= */
    case 'get_clienti':
      $stmt = $pdo->prepare('SELECT ID_CLIENTE, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL
                             FROM CLIENTI WHERE UID=? ORDER BY COGNOME, NOME');
      $stmt->execute([$uid]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_cliente':
      $sql = 'INSERT INTO CLIENTI (UID, COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
              VALUES (?,?,?,?,?,?,?,?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $uid,
        $_POST['lastName'] ?? '',
        $_POST['firstName'] ?? '',
        $_POST['birthDate'] ?? date('Y-m-d'),
        $_POST['address'] ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone'] ?? null,
        $_POST['email'] ?? null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_cliente':
      $sql='UPDATE CLIENTI SET COGNOME=?,NOME=?,DATA_NASCITA=?,INDIRIZZO=?,CODICE_FISCALE=?,TELEFONO=?,EMAIL=?
            WHERE ID_CLIENTE=? AND UID=?';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName'] ?? '',
        $_POST['firstName'] ?? '',
        $_POST['birthDate'] ?? date('Y-m-d'),
        $_POST['address'] ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone'] ?? null,
        $_POST['email'] ?? null,
        (int)($_POST['id'] ?? 0),
        $uid,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_cliente':
      $stmt=$pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $stmt->execute([(int)($_POST['id'] ?? 0), $uid]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *       MISURAZIONI (UID)
 * ========================= */
    case 'get_misurazioni':
      $cid = (int)($_GET['clientId'] ?? $_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$cid, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $stmt=$pdo->prepare('SELECT * FROM MISURAZIONI WHERE UID=? AND ID_CLIENTE=? ORDER BY DATA_MISURAZIONE DESC');
      $stmt->execute([$uid, $cid]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_misurazione':
      $cid = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$cid, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $sql='INSERT INTO MISURAZIONI
            (UID, ID_CLIENTE, DATA_MISURAZIONE, PESO, ALTEZZA, TORACE, VITA, FIANCHI, BRACCIO_SX, BRACCIO_DX, COSCIA_SX, COSCIA_DX)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $uid, $cid,
        $_POST['date'] ?? date('Y-m-d'),
        $_POST['weight'] ?? null,
        $_POST['height'] ?? null,
        $_POST['chest'] ?? null,
        $_POST['waist'] ?? null,
        $_POST['hips'] ?? null,
        $_POST['leftArm'] ?? null,
        $_POST['rightArm'] ?? null,
        $_POST['leftThigh'] ?? null,
        $_POST['rightThigh'] ?? null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_misurazione':
      $cid = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$cid, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $sql='UPDATE MISURAZIONI SET ID_CLIENTE=?,DATA_MISURAZIONE=?,PESO=?,ALTEZZA=?,TORACE=?,VITA=?,FIANCHI=?,BRACCIO_SX=?,BRACCIO_DX=?,COSCIA_SX=?,COSCIA_DX=?
            WHERE ID_MISURAZIONE=? AND UID=?';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $cid,
        $_POST['date'] ?? date('Y-m-d'),
        $_POST['weight'] ?? null,
        $_POST['height'] ?? null,
        $_POST['chest'] ?? null,
        $_POST['waist'] ?? null,
        $_POST['hips'] ?? null,
        $_POST['leftArm'] ?? null,
        $_POST['rightArm'] ?? null,
        $_POST['leftThigh'] ?? null,
        $_POST['rightThigh'] ?? null,
        (int)($_POST['id'] ?? 0),
        $uid,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_misurazione':
      $stmt=$pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE=? AND UID=?');
      $stmt->execute([(int)($_POST['id'] ?? 0), $uid]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *        ESERCIZI (GLOBALI)
 * ========================= */
    case 'get_esercizi':
      $stmt=$pdo->query('SELECT * FROM ESERCIZI ORDER BY NOME');
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_esercizio':
      $sql='INSERT INTO ESERCIZI (SIGLA,NOME,DESCRIZIONE,GRUPPO_MUSCOLARE,VIDEO_URL,IMG_URL) VALUES (?,?,?,?,?,?)';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla'] ?? null,
        $_POST['nome'] ?? null,
        $_POST['descrizione'] ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl'] ?: null,
        $_POST['imgUrl']  ?: null,
      ]);
      echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
      break;

    case 'update_esercizio':
      $sql='UPDATE ESERCIZI SET SIGLA=?,NOME=?,DESCRIZIONE=?,GRUPPO_MUSCOLARE=?,VIDEO_URL=?,IMG_URL=? WHERE ID_ESERCIZIO=?';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla'] ?? null,
        $_POST['nome'] ?? null,
        $_POST['descrizione'] ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl'] ?: null,
        $_POST['imgUrl']  ?: null,
        (int)($_POST['id'] ?? 0),
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_esercizio':
      $stmt=$pdo->prepare('DELETE FROM ESERCIZI WHERE ID_ESERCIZIO=?');
      $stmt->execute([(int)($_POST['id'] ?? 0)]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *     TIPI APPUNTAMENTO (GLOBALI)
 * ========================= */
    case 'get_tipo_appuntamento':
      $stmt=$pdo->query("SELECT ID_AGGETTIVO AS CODICE, DESCRIZIONE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE='TIPO_APPUNTAMENTO' ORDER BY ORDINE");
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

/* =========================
 *        APPUNTAMENTI (UID)
 * ========================= */
    case 'get_appuntamenti':
      $stmt=$pdo->prepare("SELECT a.ID_APPUNTAMENTO,a.ID_CLIENTE,a.DATA_ORA,a.TIPOLOGIA,a.NOTE,c.NOME,c.COGNOME
                           FROM APPUNTAMENTI a
                           JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE AND c.UID=?
                           WHERE a.UID=?
                           ORDER BY a.DATA_ORA ASC");
      $stmt->execute([$uid, $uid]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_appuntamento':
      $cid = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$cid, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $stmt=$pdo->prepare("INSERT INTO APPUNTAMENTI (UID, ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE) VALUES (?,?,?,?,?)");
      $stmt->execute([
        $uid,
        $cid,
        $_POST['datetime'] ?? date('Y-m-d H:i:s'),
        $_POST['typeCode'] ?? 'GENE',
        $_POST['note']     ?: null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_appuntamento':
      $cid = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$cid, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $stmt=$pdo->prepare("UPDATE APPUNTAMENTI SET ID_CLIENTE=?,DATA_ORA=?,TIPOLOGIA=?,NOTE=? WHERE ID_APPUNTAMENTO=? AND UID=?");
      $stmt->execute([
        $cid,
        $_POST['datetime'] ?? date('Y-m-d H:i:s'),
        $_POST['typeCode'] ?? 'GENE',
        $_POST['note']     ?: null,
        (int)($_POST['id'] ?? 0),
        $uid,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_appuntamento':
      $stmt=$pdo->prepare("DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=? AND UID=?");
      $stmt->execute([(int)($_POST['id'] ?? 0), $uid]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *   SCHEDE (TESTA) (UID) — SCHEDE_ESERCIZI_TESTA
 * ========================= */
    case 'get_schede_testa':
      $clientId = isset($_GET['clientId']) ? (int)$_GET['clientId'] : (isset($_POST['clientId']) ? (int)$_POST['clientId'] : 0);
      if ($clientId) {
        $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
        $chk->execute([$clientId, $uid]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

        $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE UID=? AND ID_CLIENTE=? ORDER BY ID_SCHEDAT DESC");
        $stmt->execute([$uid, $clientId]);
      } else {
        $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_TESTA WHERE UID=? ORDER BY ID_SCHEDAT DESC");
        $stmt->execute([$uid]);
      }
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_scheda_testa':
      $clientId = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$clientId, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $sql = "INSERT INTO SCHEDE_ESERCIZI_TESTA
              (UID, ID_CLIENTE, DATA_INIZIO, NUM_SETIMANE, GIORNI_A_SET, NOTE)
              VALUES (?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $uid,
        $clientId,
        $_POST['dataInizio']    ?? date('Y-m-d'),
        $_POST['numSettimane']  ?? 3,   // campo nel DB: NUM_SETIMANE (una T)
        $_POST['giorniASet']    ?? 5,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_scheda_testa':
      $clientId = (int)($_POST['clientId'] ?? 0);
      $chk = $pdo->prepare('SELECT 1 FROM CLIENTI WHERE ID_CLIENTE=? AND UID=?');
      $chk->execute([$clientId, $uid]);
      if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Client not found']); break; }

      $sql = "UPDATE SCHEDE_ESERCIZI_TESTA
              SET ID_CLIENTE=?, DATA_INIZIO=?, NUM_SETIMANE=?, GIORNI_A_SET=?, NOTE=?
              WHERE ID_SCHEDAT=? AND UID=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $clientId,
        $_POST['dataInizio']    ?? date('Y-m-d'),
        $_POST['numSettimane']  ?? 3,   // NUM_SETIMANE
        $_POST['giorniASet']    ?? 5,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        (int)($_POST['id_scheda'] ?? 0),
        $uid,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_scheda_testa':
      $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_TESTA WHERE ID_SCHEDAT=? AND UID=?");
      $stmt->execute([(int)($_POST['id'] ?? 0), $uid]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *  VOCI DI SCHEDA (UID) — SCHEDE_ESERCIZI_DETTA
 * ========================= */
    case 'get_voci_scheda':
      $idScheda = (int)($_GET['id_scheda'] ?? $_POST['id_scheda'] ?? 0);
      if (!$idScheda) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id_scheda mancante']); break; }

      $own = $pdo->prepare("SELECT 1 FROM SCHEDE_ESERCIZI_TESTA WHERE ID_SCHEDAT=? AND UID=?");
      $own->execute([$idScheda, $uid]);
      if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Plan not found']); break; }

      $stmt = $pdo->prepare("SELECT * FROM SCHEDE_ESERCIZI_DETTA WHERE UID=? AND ID_SCHEDAT=? ORDER BY SETTIMANA, GIORNO, ORDINE, ID_SCHEDAD");
      $stmt->execute([$uid, $idScheda]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_voce_scheda':
      $idScheda = (int)($_POST['id_scheda'] ?? 0);
      $own = $pdo->prepare("SELECT 1 FROM SCHEDE_ESERCIZI_TESTA WHERE ID_SCHEDAT=? AND UID=?");
      $own->execute([$idScheda, $uid]);
      if (!$own->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'Plan not found']); break; }

      // campi NOT NULL interi: SERIE, RIPETIZIONI, PESO, REST, ORDINE
      $serie       = (int)($_POST['serie'] ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso'] ?? 0);
      $rest        = (int)($_POST['rest'] ?? 0);
      $ordine      = (int)($_POST['ordine'] ?? 0);

      $sql = "INSERT INTO SCHEDE_ESERCIZI_DETTA
              (UID, ID_SCHEDAT, ID_ESERCIZIO, SETTIMANA, GIORNO, SERIE, RIPETIZIONI, PESO, REST, ORDINE, NOTE)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $uid,
        $idScheda,
        $_POST['id_esercizio'] ?? null,
        $_POST['settimana'] ?? 1,
        $_POST['giorno'] ?? 1,
        $serie,
        $ripetizioni,
        $peso,
        $rest,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_voce_scheda':
      $serie       = (int)($_POST['serie'] ?? 0);
      $ripetizioni = (int)($_POST['ripetizioni'] ?? 0);
      $peso        = (int)($_POST['peso'] ?? 0);
      $rest        = (int)($_POST['rest'] ?? 0);
      $ordine      = (int)($_POST['ordine'] ?? 0);

      $sql = "UPDATE SCHEDE_ESERCIZI_DETTA
              SET ID_ESERCIZIO=?, SETTIMANA=?, GIORNO=?, SERIE=?, RIPETIZIONI=?, PESO=?, REST=?, ORDINE=?, NOTE=?
              WHERE ID_SCHEDAD=? AND UID=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['id_esercizio'] ?? null,
        $_POST['settimana'] ?? 1,
        $_POST['giorno'] ?? 1,
        $serie,
        $ripetizioni,
        $peso,
        $rest,
        $ordine,
        ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        (int)($_POST['id_voce'] ?? 0),
        $uid,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_voce_scheda':
      $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAD=? AND UID=?");
      $stmt->execute([(int)($_POST['id_voce'] ?? 0), $uid]);
      echo json_encode(['success'=>true]);
      break;

/* =========================
 *     GRUPPI MUSCOLARI (GLOBALI)
 * ========================= */
    case 'get_gruppi_muscolari':
      try {
        $stmt = $pdo->prepare("
          SELECT 
            ID_AGGETTIVO AS code,
            DESCRIZIONE  AS name,
            IFNULL(COMMENTO,'') AS comment,
            ORDINE       AS ordine
          FROM REFERENZECOMBO_0099
          WHERE ID_CLASSE='GRUPPO_MUSCOLARE'
          ORDER BY ORDINE, DESCRIZIONE
        ");
        $stmt->execute();
        echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
      }
      break;

    case 'insert_gruppo_muscolare':
      try {
        $code    = $_POST['code']    ?? null;
        $name    = $_POST['name']    ?? null;
        $comment = $_POST['comment'] ?? null;
        $ordine  = $_POST['ordine']  ?? null;
        if (!$code || !$name) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Parametri code e name obbligatori']); break; }
        if ($ordine === null || $ordine === '') { $ordine = 999; }

        $sql = "INSERT INTO REFERENZECOMBO_0099
                (ID_CLASSE, ID_AGGETTIVO, DESCRIZIONE, COMMENTO, ORDINE)
                VALUES ('GRUPPO_MUSCOLARE', ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$code, $name, ($comment !== '' ? $comment : null), (int)$ordine]);

        echo json_encode(['success'=>true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
      }
      break;

    case 'update_gruppo_muscolare':
      try {
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
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
      }
      break;

    case 'delete_gruppo_muscolare':
      try {
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

        $stmt = $pdo->prepare("
          DELETE FROM REFERENZECOMBO_0099 
          WHERE ID_CLASSE='GRUPPO_MUSCOLARE' AND ID_AGGETTIVO=?
        ");
        $stmt->execute([$code]);

        echo json_encode(['success'=>true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
      }
      break;

/* =========================
 *         DEFAULT
 * ========================= */
    default:
      echo json_encode(['success'=>false,'error'=>'Azione non riconosciuta: '.$action]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
}
