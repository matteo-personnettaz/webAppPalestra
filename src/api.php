<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$dbSocket = getenv('DB_HOST'); // /cloudsql/project:region:instance
$dbName   = getenv('DB_NAME');
$dbUser   = getenv('DB_USER');
$dbPass   = getenv('DB_PASS');

$dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $dbSocket, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,        // opzionale
        PDO::ATTR_PERSISTENT         => false,    // meglio no su Cloud Run
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connessione fallita: ' . $e->getMessage()]);
    exit;
}


$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 🎯 Routing delle azioni
switch ($action) {
    case 'ping':
        // nessuna query, solo un JSON di conferma
        echo json_encode(['success' => true, 'message' => 'pong']);
        break;
    case 'get_clienti':
        try {
            $stmt = $pdo->query('SELECT * FROM CLIENTI ORDER BY COGNOME, NOME');
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'insert_cliente':
        try {
            $sql = 'INSERT INTO CLIENTI (COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['lastName'],
                $_POST['firstName'],
                $_POST['birthDate'],
                $_POST['address'],
                $_POST['fiscalCode'],
                $_POST['phone'],
                $_POST['email'],
            ]);
            echo json_encode(['insertId' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'update_cliente':
        try {
            $sql = 'UPDATE CLIENTI SET COGNOME=?, NOME=?, DATA_NASCITA=?, INDIRIZZO=?, CODICE_FISCALE=?, TELEFONO=?, EMAIL=? WHERE ID_CLIENTE=?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['lastName'],
                $_POST['firstName'],
                $_POST['birthDate'],
                $_POST['address'],
                $_POST['fiscalCode'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['id'],
            ]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'delete_cliente':
        try {
            $stmt = $pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE = ?');
            $stmt->execute([$_POST['id']]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    //
    // MISURAZIONI
    //
    case 'get_misurazioni':
        try {
            $stmt = $pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE = ? ORDER BY DATA_MISURAZIONE DESC');
            $stmt->execute([$_GET['clientId'] ?? $_POST['clientId']]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;
    case 'insert_misurazione':
        try {
            $params = [
                $_POST['clientId'],
                $_POST['date'],
                $_POST['weight'],
                $_POST['height'],
                $_POST['chest'],
                $_POST['waist'],
                $_POST['hips'],
                $_POST['leftArm'],
                $_POST['rightArm'],
                $_POST['leftThigh'],
                $_POST['rightThigh'],
            ];
            $sql = 'INSERT INTO MISURAZIONI (ID_CLIENTE, DATA_MISURAZIONE, PESO, ALTEZZA, TORACE, VITA, FIANCHI, BRACCIO_SX, BRACCIO_DX, COSCIA_SX, COSCIA_DX) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['insertId' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;
    case 'update_misurazione':
        try {
            $sql = 'UPDATE MISURAZIONI SET ID_CLIENTE=?, DATA_MISURAZIONE=?, PESO=?, ALTEZZA=?, TORACE=?, VITA=?, FIANCHI=?, 
                    BRACCIO_SX=?, BRACCIO_DX=?, COSCIA_SX=?, COSCIA_DX=? WHERE ID_MISURAZIONE=?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['clientId'],
                $_POST['date'],
                $_POST['weight'],
                $_POST['height'],
                $_POST['chest'],
                $_POST['waist'],
                $_POST['hips'],
                $_POST['leftArm'],
                $_POST['rightArm'],
                $_POST['leftThigh'],
                $_POST['rightThigh'],
                $_POST['id'],
            ]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;
    case 'delete_misurazione':
        // pulisci e valida l’ID in ingresso
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM MISURAZIONI WHERE ID_MISURAZIONE = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ID non valido']);
        }
        break;

    //
    // ESERCIZI
    //
    case 'get_esercizi':
        try {
            $stmt = $pdo->query('SELECT * FROM ESERCIZI ORDER BY NOME');
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'insert_esercizio':
        try {
            $stmt = $pdo->prepare('INSERT INTO ESERCIZI (SIGLA, NOME, DESCRIZIONE, GRUPPO_MUSCOLARE, VIDEO_URL, IMG_URL) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $_POST['sigla'],
                $_POST['nome'],
                $_POST['descrizione'],          // (opzionale)
                $_POST['gruppoMuscolare'],      // (opzionale)
                $_POST['videoUrl'] ?: null,     // (opzionale)
                $_POST['imgUrl'] ?: null,       // (opzionale)
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'update_esercizio':
        try {
            $stmt = $pdo->prepare(
                'UPDATE ESERCIZI SET SIGLA = ?, NOME = ?, DESCRIZIONE = ?, GRUPPO_MUSCOLARE = ?, VIDEO_URL = ?, IMG_URL = ? WHERE ID_ESERCIZIO = ?'
            );
            $stmt->execute([
                $_POST['sigla'],
                $_POST['nome'],
                $_POST['descrizione'],          // (opzionale)
                $_POST['gruppoMuscolare'],      // (opzionale)
                $_POST['videoUrl'] ?: null,     // (opzionale)
                $_POST['imgUrl'] ?: null,       // (opzionale)
                (int)$_POST['id'],
            ]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'delete_esercizio':
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM ESERCIZI WHERE ID_ESERCIZIO = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ID non valido']);
        }
        break;

    //
    // APPUNTAMENTI
    //
    case 'get_tipo_appuntamento':
        try {
            $stmt = $pdo->query('SELECT ID_AGGETTIVO as CODICE, DESCRIZIONE FROM REFERENZECOMBO_0099 WHERE ID_CLASSE=\'TIPO_APPUNTAMENTO\' ORDER BY ORDINE');
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;
    case 'get_appuntamenti':
        try {
            $stmt = $pdo->prepare(
               "SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.TIPOLOGIA, a.NOTE, c.NOME, c.COGNOME
                FROM APPUNTAMENTI a
                JOIN CLIENTI c ON a.ID_CLIENTE = c.ID_CLIENTE
                -- WHERE a.DATA_ORA >= NOW()
                ORDER BY a.DATA_ORA ASC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'insert_appuntamento':
        try {
            $stmt = $pdo->prepare("INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, TIPOLOGIA, NOTE) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_POST['clientId'],
                $_POST['datetime'],
                $_POST['typeCode'],
                $_POST['note'] ?: null
            ]);
            echo json_encode(['success' => true, 'insertId' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'update_appuntamento':
        try {
            $stmt = $pdo->prepare("UPDATE APPUNTAMENTI SET ID_CLIENTE = ?, DATA_ORA = ?, TIPOLOGIA = ?, NOTE = ? WHERE ID_APPUNTAMENTO = ?");
            $stmt->execute([
                $_POST['clientId'],
                $_POST['datetime'],
                $_POST['typeCode'],
                $_POST['note'] ?: null,
                $_POST['id']
            ]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'DB error: ' . $e->getMessage()
            ]);
            exit;
        }
        break;

    case 'delete_appuntamento':
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ID non valido']);
        }
        break;

    ///
    // SCHEDE ESERCIZI
    ///

    // 1) Legge tutte le schede (testa)
    case 'get_schede_testa':
        $stmt = $pdo->prepare("
        SELECT s.ID_SCHEDAT, s.ID_CLIENTE, s.DATA_INIZIO, s.NUM_SETIMANE, s.GIORNI_A_SET, s.NOTE,
                c.NOME, c.COGNOME
            FROM SCHEDE_ESERCIZI_TESTA s
            JOIN CLIENTI c ON s.ID_CLIENTE = c.ID_CLIENTE
        ORDER BY s.DATA_INIZIO DESC
        ");
        $stmt->execute();
        echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // 2) Legge tutte le voci (dettagli) di una scheda
    case 'get_voci_scheda':
        $stmt = $pdo->prepare("
        SELECT d.ID_SCHEDAD, d.ID_SCHEDAT, d.ID_ESERCIZIO, d.SETTIMANA, d.GIORNO,
                d.SERIE, d.RIPETIZIONI, d.PESO, d.REST, d.ORDINE, d.NOTE,
                e.SIGLA, e.NOME AS ES_NAME
            FROM SCHEDE_ESERCIZI_DETTA d
            JOIN ESERCIZI e ON d.ID_ESERCIZIO = e.ID_ESERCIZIO
        WHERE d.ID_SCHEDAT = ?
        ORDER BY d.SETTIMANA, d.GIORNO, d.ORDINE
        ");
        $stmt->execute([$_REQUEST['id_scheda']]);
        echo json_encode(['success'=>true, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // 3) Inserisce una nuova scheda (testa)
    case 'insert_scheda_testa':
        $stmt = $pdo->prepare("
        INSERT INTO SCHEDE_ESERCIZI_TESTA
            (ID_CLIENTE, DATA_INIZIO, NUM_SETIMANE, GIORNI_A_SET, NOTE)
        VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
        $_POST['clientId'],
        $_POST['dataInizio'],
        $_POST['numSettimane'],
        $_POST['giorniASet'],
        $_POST['note'] ?? ''
        ]);
        echo json_encode(['success'=>true, 'insertId'=>$pdo->lastInsertId()]);
        break;

    // 4) Aggiorna una scheda (testa)
    case 'update_scheda_testa':
        $stmt = $pdo->prepare("
        UPDATE SCHEDE_ESERCIZI_TESTA
            SET ID_CLIENTE=?, DATA_INIZIO=?, NUM_SETIMANE=?, GIORNI_A_SET=?, NOTE=?
        WHERE ID_SCHEDAT=?
        ");
        $stmt->execute([
        $_POST['clientId'],
        $_POST['dataInizio'],
        $_POST['numSettimane'],
        $_POST['giorniASet'],
        $_POST['note'] ?? '',
        $_POST['id_scheda']
        ]);
        echo json_encode(['success'=>true]);
        break;

    // 5) Elimina una scheda (testa) e le voci collegate
    case 'delete_scheda_testa':
        $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_TESTA WHERE ID_SCHEDAT=?");
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success'=>true]);
        break;

    // 6) Inserisce una voce di scheda (dettaglio)
    case 'insert_voce_scheda':
        $stmt = $pdo->prepare("
        INSERT INTO SCHEDE_ESERCIZI_DETTA
            (ID_SCHEDAT, ID_ESERCIZIO, SETTIMANA, GIORNO, SERIE, RIPETIZIONI, PESO, REST, ORDINE, NOTE)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
        $_POST['id_schedat'],
        $_POST['id_esercizio'],
        $_POST['settimana'],
        $_POST['giorno'],
        $_POST['serie'],
        $_POST['ripetizioni'],
        $_POST['peso'],
        $_POST['rest'],
        $_POST['ordine'],
        $_POST['note'] ?? ' '
        ]);
        echo json_encode(['success'=>true,'insertId'=>$pdo->lastInsertId()]);
        break;

    // 7) Aggiorna una voce di scheda
    case 'update_voce_scheda':
        $stmt = $pdo->prepare("
        UPDATE SCHEDE_ESERCIZI_DETTA
            SET ID_ESERCIZIO=?, SETTIMANA=?, GIORNO=?, SERIE=?, RIPETIZIONI=?, PESO=?, REST=?, ORDINE=?, NOTE=?
        WHERE ID_SCHEDAD=?
        ");
        $stmt->execute([
        $_POST['id_esercizio'],
        $_POST['settimana'],
        $_POST['giorno'],
        $_POST['serie'],
        $_POST['ripetizioni'],
        $_POST['peso'],
        $_POST['rest'],
        $_POST['ordine'],
        $_POST['note'] ?? '',
        $_POST['id_voce']
        ]);
        echo json_encode(['success'=>true]);
        break;

    // 8) Elimina una voce di scheda
    case 'delete_voce_scheda':
        $stmt = $pdo->prepare("DELETE FROM SCHEDE_ESERCIZI_DETTA WHERE ID_SCHEDAD=?");
        $stmt->execute([$_POST['id_voce']]);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['error' => 'Azione non riconosciuta: ' . $action]);
}
