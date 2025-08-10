<?php
// CORS & JSON
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if (($_GET['action'] ?? $_POST['action'] ?? '') === 'whoami') {
  echo json_encode([
    'ok' => true,
    'rev' => getenv('K_REVISION') ?: 'n/a',
    'has_socket' => getenv('DB_SOCKET') ?: 'n/a'
  ]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// === API KEY ===
const API_KEY = '9390f9115c45f1338b17949e3e39f94fd9afcbd414c07fd2a2e906ffd22469e8';
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== API_KEY) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Chiave API non valida']);
  exit;
}

// === Action ===
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ping “light”: nessun accesso DB
if ($action === 'ping') {
  echo json_encode(['success' => true, 'message' => 'pong']);
  exit;
}

// === Parametri DB da env ===
// Preferiamo il socket Cloud SQL (Cloud Run + Add connection)
// Esempio: /cloudsql/cloud-palestra-athena:us-east1:fitness-manager
$DB_NAME   = getenv('DB_NAME')   ?: '';
$DB_USER   = getenv('DB_USER')   ?: '';
$DB_PASS   = getenv('DB_PASS')   ?: '';
$DB_SOCKET = getenv('DB_SOCKET') ?: '';  // <-- da impostare su Cloud Run
$DB_HOST   = getenv('DB_HOST')   ?: '';  // opzionale: TCP (es. 127.0.0.1) se servisse

// Costruzione DSN (socket prima, TCP come fallback)
$dsn = '';
if (!empty($DB_SOCKET)) {
  $dsn = "mysql:unix_socket={$DB_SOCKET};dbname={$DB_NAME};charset=utf8mb4";
} elseif (!empty($DB_HOST)) {
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
} else {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Config DB mancante (DB_SOCKET o DB_HOST).']);
  exit;
}

// Connessione PDO
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Connessione fallita: ' . $e->getMessage()]);
  exit;
}

// === Routing ===
switch ($action) {
  // CLIENTI
  case 'get_clienti':
    try {
      $stmt = $pdo->query('SELECT * FROM CLIENTI ORDER BY COGNOME, NOME');
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'insert_cliente':
    try {
      $sql = 'INSERT INTO CLIENTI (COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
              VALUES (?, ?, ?, ?, ?, ?, ?)';
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
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'update_cliente':
    try {
      $sql = 'UPDATE CLIENTI SET COGNOME=?, NOME=?, DATA_NASCITA=?, INDIRIZZO=?, CODICE_FISCALE=?, TELEFONO=?, EMAIL=?
              WHERE ID_CLIENTE=?';
      $stmt = $pdo->prepare($sql);
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
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'delete_cliente':
    try {
      $stmt = $pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE = ?');
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  // MISURAZIONI
  case 'get_misurazioni':
    try {
      $cid = $_GET['clientId'] ?? $_POST['clientId'] ?? null;
      $stmt = $pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE = ? ORDER BY DATA_MISURAZIONE DESC');
      $stmt->execute([$cid]);
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'insert_misurazione':
    try {
      $sql = 'INSERT INTO MISURAZIONI
              (ID_CLIENTE, DATA_MISURAZIONE, PESO, ALTEZZA, TORACE, VITA, FIANCHI, BRACCIO_SX, BRACCIO_DX, COSCIA_SX, COSCIA_DX)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
      $stmt = $pdo->prepare($sql);
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
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'update_misurazione':
    try {
      $sql = 'UPDATE MISURAZIONI SET
                ID_CLIENTE=?, DATA_MISURAZIONE=?, PESO=?, ALTEZZA=?, TORACE=?, VITA=?, FIANCHI=?,
                BRACCIO_SX=?, BRACCIO_DX=?, COSCIA_SX=?, COSCIA_DX=?
              WHERE ID_MISURAZIONE=?';
      $stmt = $pdo->prepare($sql);
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
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'delete_misurazione':
    try {
      $stmt = $pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE = ?');
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  // ESERCIZI
  case 'get_esercizi':
    try {
      $stmt = $pdo->query('SELECT * FROM ESERCIZI ORDER BY NOME');
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'insert_esercizio':
    try {
      $sql = 'INSERT INTO ESERCIZI (SIGLA, NOME, DESCRIZIONE, GRUPPO_MUSCOLARE, VIDEO_URL, IMG_URL)
              VALUES (?, ?, ?, ?, ?, ?)';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla'] ?? null,
        $_POST['nome'] ?? null,
        $_POST['descrizione'] ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl'] ?: null,
        $_POST['imgUrl'] ?: null,
      ]);
      echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'update_esercizio':
    try {
      $sql = 'UPDATE ESERCIZI SET
                SIGLA=?, NOME=?, DESCRIZIONE=?, GRUPPO_MUSCOLARE=?, VIDEO_URL=?, IMG_URL=?
              WHERE ID_ESERCIZIO=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        $_POST['sigla'] ?? null,
        $_POST['nome'] ?? null,
        $_POST['descrizione'] ?? null,
        $_POST['gruppoMuscolare'] ?? null,
        $_POST['videoUrl'] ?: null,
        $_POST['imgUrl'] ?: null,
        (int)($_POST['id'] ?? 0),
      ]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'delete_esercizio':
    try {
      $stmt = $pdo->prepare('DELETE FROM ESERCIZI WHERE ID_ESERCIZIO = ?');
      $stmt->execute([(int)($_POST['id'] ?? 0)]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  // TIPO APPUNTAMENTO + APPUNTAMENTI
  case 'get_tipo_appuntamento':
    try {
      $stmt = $pdo->query("SELECT ID_AGGETTIVO as CODICE, DESCRIZIONE
                           FROM REFERENZECOMBO_0099
                           WHERE ID_CLASSE='TIPO_APPUNTAMENTO'
                           ORDER BY ORDINE");
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'get_appuntamenti':
    try {
      $stmt = $pdo->prepare(
        "SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, c.NOME, c.COGNOME
         FROM APPUNTAMENTI a
         JOIN CLIENTI c ON a.ID_CLIENTE = c.ID_CLIENTE
         ORDER BY a.DATA_ORA ASC"
      );
      $stmt->execute();
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'insert_appuntamento':
    try {
      $stmt = $pdo->prepare("INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE) VALUES (?, ?, ?, ?)");
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['datetime'] ?? null,
        $_POST['typeCode'] ?? null,
        $_POST['note'] ?: null,
      ]);
      echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'update_appuntamento':
    try {
      $stmt = $pdo->prepare("UPDATE APPUNTAMENTI SET ID_CLIENTE=?, DATA_ORA=?, TIPOLOGIA=?, NOTE=? WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([
        $_POST['clientId'] ?? null,
        $_POST['datetime'] ?? null,
        $_POST['typeCode'] ?? null,
        $_POST['note'] ?: null,
        $_POST['id'] ?? null,
      ]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  case 'delete_appuntamento':
    try {
      $stmt = $pdo->prepare("DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO=?");
      $stmt->execute([$_POST['id'] ?? 0]);
      echo json_encode(['success' => true]);
    } catch (PDOException $e) {
      http_response_code(500);
      echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    }
    break;

  default:
    echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta: ' . $action]);
}