<?php
declare(strict_types=1);

/**
 * API Palestra – Cloud SQL ready (socket > tcp)
 * - Cloud Run / Connector: DB_SOCKET = /cloudsql/<PROJECT:REGION:INSTANCE>
 * - Proxy locale/sidecar:  DB_HOST=127.0.0.1  DB_PORT=3307
 */

/* ===== CORS & JSON ===== */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

/* ===== API KEY ===== */
/*const API_KEY = '9390f9115c45f1338b17949e3e39f94fd9afcbd414c07fd2a2e906ffd22469e8';*/
$API_KEY = getenv('API_KEY') ?: 'override_me_in_prod';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$key    = $_GET['key']    ?? $_POST['key']    ?? '';

/* ===== ENV CONFIG ===== */
$CFG = [
  'DB_NAME'   => getenv('DB_NAME')   ?: 'fitness_db',
  'DB_USER'   => getenv('DB_USER')   ?: '',
  'DB_PASS'   => getenv('DB_PASS')   ?: '',
  'DB_SOCKET' => getenv('DB_SOCKET') ?: '', // es: /cloudsql/cloud-palestra-athena:us-east1:root
  'DB_HOST'   => getenv('DB_HOST')   ?: '', // es: 127.0.0.1 (proxy) o IP pubblico (solo test)
  'DB_PORT'   => (int)(getenv('DB_PORT') ?: 3306),
];

/* ===== ROUTE: ping / whoami (no DB) ===== */
if ($action === 'ping') {
  echo json_encode(['success'=>true,'message'=>'pong']); exit;
}
if ($action === 'whoami') {
  echo json_encode([
    'ok'  => true,
    'rev' => getenv('K_REVISION') ?: 'n/a',
    'has_socket' => $CFG['DB_SOCKET'] ?: 'n/a',
  ]);
  exit;
}

/* ===== ROUTE: socketcheck (no DB) ===== */
if ($action === 'socketcheck') {
  $sock = $CFG['DB_SOCKET'];
  echo json_encode([
    'dir_exists'  => is_dir('/cloudsql'),
    'sock'        => $sock,
    'sock_exists' => $sock ? file_exists($sock) : null,
  ]);
  exit;
}

/* ===== Auth per le rotte che toccano il DB (tutte le altre) ===== */
if (!in_array($action, ['ping','whoami','socketcheck'], true)) {
  if ($key !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Chiave API non valida']); exit;
  }
}

/* ===== PDO factory (socket > tcp) ===== */
function connect_pdo(array $cfg): array {
  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];
  $tried = [];
  // 1) UNIX SOCKET
  if (!empty($cfg['DB_SOCKET'])) {
    $dsn = "mysql:unix_socket={$cfg['DB_SOCKET']};dbname={$cfg['DB_NAME']};charset=utf8mb4";
    $tried[] = $dsn;
    try { return [new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], $opt), 'unix_socket', $tried]; }
    catch (Throwable $e) { $last = $e->getMessage(); }
  }
  // 2) TCP
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
    /* === CLIENTI === */
    case 'get_clienti':
      $stmt = $pdo->query('SELECT * FROM CLIENTI ORDER BY COGNOME, NOME');
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_cliente':
      $sql = 'INSERT INTO CLIENTI (COGNOME,NOME,DATA_NASCITA,INDIRIZZO,CODICE_FISCALE,TELEFONO,EMAIL)
              VALUES (?,?,?,?,?,?,?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName'] ?? null,
        $_POST['firstName'] ?? null,
        $_POST['birthDate'] ?? null,
        $_POST['address'] ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone'] ?? null,
        $_POST['email'] ?? null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_cliente':
      $sql='UPDATE CLIENTI SET COGNOME=?,NOME=?,DATA_NASCITA=?,INDIRIZZO=?,CODICE_FISCALE=?,TELEFONO=?,EMAIL=? WHERE ID_CLIENTE=?';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['lastName'] ?? null,
        $_POST['firstName'] ?? null,
        $_POST['birthDate'] ?? null,
        $_POST['address'] ?? null,
        $_POST['fiscalCode'] ?? null,
        $_POST['phone'] ?? null,
        $_POST['email'] ?? null,
        $_POST['id'] ?? null,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_cliente':
      $stmt=$pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE=?');
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success'=>true]);
      break;

    /* === MISURAZIONI === */
    case 'get_misurazioni':
      $cid = $_GET['clientId'] ?? $_POST['clientId'] ?? null;
      $stmt=$pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE=? ORDER BY DATA_MISURAZIONE DESC');
      $stmt->execute([$cid]);
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_misurazione':
      $sql='INSERT INTO MISURAZIONI (ID_CLIENTE,DATA_MISURAZIONE,PESO,ALTEZZA,TORACE,VITA,FIANCHI,BRACCIO_SX,BRACCIO_DX,COSCIA_SX,COSCIA_DX)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['date'] ?? null,
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
      $sql='UPDATE MISURAZIONI SET ID_CLIENTE=?,DATA_MISURAZIONE=?,PESO=?,ALTEZZA=?,TORACE=?,VITA=?,FIANCHI=?,BRACCIO_SX=?,BRACCIO_DX=?,COSCIA_SX=?,COSCIA_DX=? WHERE ID_MISURAZIONE=?';
      $stmt=$pdo->prepare($sql);
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['date'] ?? null,
        $_POST['weight'] ?? null,
        $_POST['height'] ?? null,
        $_POST['chest'] ?? null,
        $_POST['waist'] ?? null,
        $_POST['hips'] ?? null,
        $_POST['leftArm'] ?? null,
        $_POST['rightArm'] ?? null,
        $_POST['leftThigh'] ?? null,
        $_POST['rightThigh'] ?? null,
        $_POST['id'] ?? null,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_misurazione':
      $stmt=$pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE=?');
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success'=>true]);
      break;

    /* === ESERCIZI === */
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

    /* === APPUNTAMENTI === */
    case 'get_tipo_appuntamento':
      $stmt=$pdo->query("SELECT ID_AGGETTIVO AS CODICE, DESCRIZIONE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE='TIPO_APPUNTAMENTO' ORDER BY ORDINE");
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'get_appuntamenti':
      $stmt=$pdo->prepare("SELECT a.ID_APPUNTAMENTO,a.ID_CLIENTE,a.DATA_ORA,a.TIPOLOGIA,a.NOTE,c.NOME,c.COGNOME
                           FROM APPUNTAMENTI a JOIN CLIENTI c ON a.ID_CLIENTE=c.ID_CLIENTE
                           ORDER BY a.DATA_ORA ASC");
      $stmt->execute();
      echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
      break;

    case 'insert_appuntamento':
      $stmt=$pdo->prepare("INSERT INTO APPUNTAMENTI (ID_CLIENTE,DATA_ORA,TIPOLOGIA,NOTE) VALUES (?,?,?,?)");
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['datetime'] ?? null,
        $_POST['typeCode'] ?? null,
        $_POST['note']     ?: null,
      ]);
      echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
      break;

    case 'update_appuntamento':
      $stmt=$pdo->prepare("UPDATE APPUNTAMENTI SET ID_CLIENTE=?,DATA_ORA=?,TIPOLOGIA=?,NOTE=? WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['datetime'] ?? null,
        $_POST['typeCode'] ?? null,
        $_POST['note']     ?: null,
        $_POST['id']       ?? null,
      ]);
      echo json_encode(['success'=>true]);
      break;

    case 'delete_appuntamento':
      $stmt=$pdo->prepare("DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success'=>true]);
      break;

    // =========================
    // SCHEDE (TESTA / PIANI)
    // =========================
    case 'get_schede_testa':
      try {
        // opzionale: filtra per clientId se passato
        $clientId = $_GET['clientId'] ?? $_POST['clientId'] ?? null;
        if ($clientId) {
          $stmt = $pdo->prepare("SELECT *
                                FROM SCHEDE_TESTA
                                WHERE ID_CLIENTE = ?
                                ORDER BY ID_SCHEDA DESC");
          $stmt->execute([(int)$clientId]);
        } else {
          $stmt = $pdo->query("SELECT *
                              FROM SCHEDE_TESTA
                              ORDER BY ID_SCHEDA DESC");
        }
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'insert_scheda_testa':
      try {
        $sql = "INSERT INTO SCHEDE_TESTA (ID_CLIENTE, DATA_INIZIO, NUM_SETTIMANE, GIORNI_A_SET, NOTE)
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          $_POST['clientId'] ?? null,
          // la tua app invia YYYY-MM-DD
          $_POST['dataInizio'] ?? null,
          $_POST['numSettimane'] ?? null,
          $_POST['giorniASet'] ?? null,
          ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        ]);
        echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'update_scheda_testa':
      try {
        $sql = "UPDATE SCHEDE_TESTA
                SET ID_CLIENTE=?, DATA_INIZIO=?, NUM_SETTIMANE=?, GIORNI_A_SET=?, NOTE=?
                WHERE ID_SCHEDAT=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          $_POST['clientId'] ?? null,
          $_POST['dataInizio'] ?? null,
          $_POST['numSettimane'] ?? null,
          $_POST['giorniASet'] ?? null,
          ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
          $_POST['id_scheda'] ?? null,
        ]);
        echo json_encode(['success' => true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'delete_scheda_testa':
      try {
        $stmt = $pdo->prepare("DELETE FROM SCHEDE_TESTA WHERE ID_SCHEDAT=?");
        $stmt->execute([$_POST['id'] ?? 0]);
        echo json_encode(['success' => true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    // ===================
    // VOCI DI SCHEDA
    // ===================
    case 'get_voci_scheda':
      try {
        $idScheda = $_GET['id_scheda'] ?? $_POST['id_scheda'] ?? null;
        if (!$idScheda) {
          http_response_code(400);
          echo json_encode(['success' => false, 'error' => 'id_scheda mancante']);
          break;
        }
        $stmt = $pdo->prepare("SELECT *
                                FROM SCHEDA_DETTA
                                WHERE ID_SCHEDAT = ?
                                ORDER BY SETTIMANA ASC, GIORNO ASC, ORDINE ASC, ID_SCHEDAD ASC");
        $stmt->execute([(int)$idScheda]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'insert_voce_scheda':
      try {
        $sql = "INSERT INTO SCHEDA_DETTA
                (ID_SCHEDAT, ID_ESERCIZIO, SETTIMANA, GIORNO, SERIE, RIPETIZIONI, PESO, REST, ORDINE, NOTE)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          $_POST['id_scheda'] ?? null,
          $_POST['id_esercizio'] ?? null,
          $_POST['settimana'] ?? null,
          $_POST['giorno'] ?? null,
          $_POST['serie'] ?? null,
          $_POST['ripetizioni'] ?? null,
          ($_POST['peso'] ?? '') !== '' ? $_POST['peso'] : null,
          ($_POST['rest'] ?? '') !== '' ? $_POST['rest'] : null,
          ($_POST['ordine'] ?? '') !== '' ? $_POST['ordine'] : null,
          ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
        ]);
        echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'update_voce_scheda':
      try {
        $sql = "UPDATE SCHEDA_DETTA
                SET ID_ESERCIZIO=?, SETTIMANA=?, GIORNO=?, SERIE=?, RIPETIZIONI=?, PESO=?, REST=?, ORDINE=?, NOTE=?
                WHERE ID_SCHEDAD=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          $_POST['id_esercizio'] ?? null,
          $_POST['settimana'] ?? null,
          $_POST['giorno'] ?? null,
          $_POST['serie'] ?? null,
          $_POST['ripetizioni'] ?? null,
          ($_POST['peso'] ?? '') !== '' ? $_POST['peso'] : null,
          ($_POST['rest'] ?? '') !== '' ? $_POST['rest'] : null,
          ($_POST['ordine'] ?? '') !== '' ? $_POST['ordine'] : null,
          ($_POST['note'] ?? '') !== '' ? $_POST['note'] : null,
          $_POST['id_voce'] ?? null,
        ]);
        echo json_encode(['success' => true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    case 'delete_voce_scheda':
      try {
        $stmt = $pdo->prepare("DELETE FROM SCHEDA_DETTA WHERE ID_SCHEDAD=?");
        $stmt->execute([$_POST['id_voce'] ?? 0]);
        echo json_encode(['success' => true]);
      } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
      }
      break;

    default:
      echo json_encode(['success'=>false,'error'=>'Azione non riconosciuta: '.$action]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'DB error: '.$e->getMessage()]);
}
