<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// 🔧 Connessione al DB
try {
    $pdo = new PDO(
        'mysql:host=tramway.proxy.rlwy.net;port=26938;dbname=fitness_manager;charset=utf8mb4',
        'root',
        'ogaXsYuCcJxFayZJnObpbUhYKYclYeOQ',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    //mysql -h tramway.proxy.rlwy.net -u root -p ogaXsYuCcJxFayZJnObpbUhYKYclYeOQ --port 26938 --protocol=TCP railway
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connessione fallita: ' . $e->getMessage()]);
    exit;
}
// 🔑 Chiave API
define('API_KEY', '9390f9115c45f1338b17949e3e39f94fd9afcbd414c07fd2a2e906ffd22469e8');

$apiKey = $_POST['key'] ?? $_GET['key'] ?? '';
if ($apiKey !== API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Chiave API non valida']);
    exit;
}


$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 🎯 Routing delle azioni
switch ($action) {
    case 'ping':
        // nessuna query, solo un JSON di conferma
        echo json_encode(['success'=> true, 'message'=>'pong']);
        break;
    case 'get_clienti':
        $stmt = $pdo->query('SELECT * FROM CLIENTI ORDER BY COGNOME, NOME');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'insert_cliente':
        $sql = 'INSERT INTO CLIENTI (COGNOME, NOME, DATA_NASCITA, INDIRIZZO, CODICE_FISCALE, TELEFONO, EMAIL)
                VALUES (?, ?, ?, ?, ?, ?, ?)';
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
        break;

    case 'update_cliente':
        $sql = 'UPDATE CLIENTI SET COGNOME=?, NOME=?, DATA_NASCITA=?, INDIRIZZO=?, CODICE_FISCALE=?, TELEFONO=?, EMAIL=?
                WHERE ID_CLIENTE=?';
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
        break;

    case 'delete_cliente':
        $stmt = $pdo->prepare('DELETE FROM CLIENTI WHERE ID_CLIENTE = ?');
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success' => true]);
        break;

    //
    // MISURAZIONI
    //
    case 'get_misurazioni':
        $stmt = $pdo->prepare('SELECT * FROM MISURAZIONI WHERE ID_CLIENTE = ? ORDER BY DATA_MISURAZIONE DESC');
        $stmt->execute([$_GET['clientId'] ?? $_POST['clientId']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'insert_misurazione':
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
    $sql = 'INSERT INTO MISURAZIONI (ID_CLIENTE, DATA_MISURAZIONE, PESO, ALTEZZA, TORACE, VITA, FIANCHI,
             BRACCIO_SX, BRACCIO_DX, COSCIA_SX, COSCIA_DX)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    foreach ($params as $p) {
        $safe = $pdo->quote($p); // aggiunge gli apici e scappa i caratteri
        $debugSql = preg_replace('/\?/', $safe, $debugSql, 1);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['insertId' => $pdo->lastInsertId()]);
    break;
    case 'update_misurazione':
        $sql = 'UPDATE MISURAZIONI SET 
                ID_CLIENTE=?, DATA_MISURAZIONE=?, PESO=?, ALTEZZA=?, TORACE=?, VITA=?, FIANCHI=?, 
                BRACCIO_SX=?, BRACCIO_DX=?, COSCIA_SX=?, COSCIA_DX=? 
                WHERE ID_MISURAZIONE=?';
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
        $stmt = $pdo->query('SELECT * FROM ESERCIZI ORDER BY NOME');
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$data]);
        break;
    
    case 'insert_esercizio':
        $stmt = $pdo->prepare(
        'INSERT INTO ESERCIZI
            (SIGLA, NOME, DESCRIZIONE, GRUPPO_MUSCOLARE, VIDEO_URL, IMG_URL)
        VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
        $_POST['sigla'],
        $_POST['nome'],
        $_POST['descrizione'],          // (opzionale)
        $_POST['gruppoMuscolare'],      // (opzionale)
        $_POST['videoUrl'] ?: null,     // (opzionale)
        $_POST['imgUrl'] ?: null,       // (opzionale)
        ]);
        echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
        break;

    case 'update_esercizio':
        $stmt = $pdo->prepare(
        'UPDATE ESERCIZI
            SET SIGLA = ?, NOME = ?, DESCRIZIONE = ?, GRUPPO_MUSCOLARE = ?, VIDEO_URL = ?, IMG_URL = ?
        WHERE ID_ESERCIZIO = ?'
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
        echo json_encode(['success'=>true]);
        break;

    case 'delete_esercizio':
        $stmt = $pdo->prepare('DELETE FROM ESERCIZI WHERE ID_ESERCIZIO = ?');
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true]);
        break;

    //
    // APPUNTAMENTI
    //
    case 'get_appuntamenti':
        $stmt = $pdo->prepare(
        "SELECT a.ID_APPUNTAMENTO, a.ID_CLIENTE, a.DATA_ORA, a.NOTE,
                c.NOME, c.COGNOME
        FROM APPUNTAMENTI a
        JOIN CLIENTI c ON a.ID_CLIENTE = c.ID_CLIENTE
        WHERE a.DATA_ORA >= NOW()
        ORDER BY a.DATA_ORA ASC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$rows]);
        break;

    case 'insert_appuntamento':
        $stmt = $pdo->prepare(
        "INSERT INTO APPUNTAMENTI (ID_CLIENTE, DATA_ORA, NOTE)
        VALUES (?, ?, ?)"
        );
        $stmt->execute([
        $_POST['clientId'],
        $_POST['datetime'],
        $_POST['note'] ?: null
        ]);
        echo json_encode(['success'=>true, 'insertId'=>$pdo->lastInsertId()]);
        break;

    case 'update_appuntamento':
        $stmt = $pdo->prepare(
        "UPDATE APPUNTAMENTI
            SET ID_CLIENTE = ?, DATA_ORA = ?, NOTE = ?
        WHERE ID_APPUNTAMENTO = ?"
        );
        $stmt->execute([
        $_POST['clientId'],
        $_POST['datetime'],
        $_POST['note'] ?: null,
        $_POST['id']
        ]);
        echo json_encode(['success'=>true]);
        break;

    case 'delete_appuntamento':
        $stmt = $pdo->prepare(
        "DELETE FROM APPUNTAMENTI WHERE ID_APPUNTAMENTO = ?"
        );
        $stmt->execute([$_POST['id']]);
        echo json_encode(['success'=>true]);
        break;

    //
    // SCHEDA_ESERCIZI
    //
    case 'get_scheda':
        $stmt = $pdo->prepare(
        'SELECT * FROM SCHEDE_ESERCIZI
            WHERE ID_CLIENTE = ?
        ORDER BY ID_SCHEDE DESC'
        );
        $stmt->execute([(int)$_POST['clientId']]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'data'=>$data]);
        break;

    case 'insert_scheda':
        $stmt = $pdo->prepare(
        'INSERT INTO SCHEDE_ESERCIZI
            (ID_CLIENTE, ID_ESERCIZIO, RIPETIZIONI, PESO, SERIE, REST, DATA_FINE)
        VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
        (int)$_POST['clientId'],
        (int)$_POST['esercizioId'],
        (int)$_POST['ripetizioni'],
        (float)$_POST['peso'],
        (int)$_POST['serie'],
        (int)$_POST['rest'],
        $_POST['dataFine'] ?: null,
        ]);
        echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId()]);
        break;

    case 'update_scheda':
        $stmt = $pdo->prepare(
        'UPDATE SCHEDE_ESERCIZI
            SET RIPETIZIONI = ?, PESO = ?, SERIE = ?, REST = ?, DATA_FINE = ?
        WHERE ID_SCHEDE = ?'
        );
        $stmt->execute([
        (int)$_POST['ripetizioni'],
        (float)$_POST['peso'],
        (int)$_POST['serie'],
        (int)$_POST['rest'],
        $_POST['dataFine'] ?: null,
        (int)$_POST['id'],
        ]);
        echo json_encode(['success'=>true]);
        break;

    case 'delete_scheda':
        $stmt = $pdo->prepare('DELETE FROM SCHEDE_ESERCIZI WHERE ID_SCHEDE = ?');
        $stmt->execute([(int)$_POST['id']]);
        echo json_encode(['success'=>true]);
        break;

    default:
        echo json_encode(['error' => 'Azione non riconosciuta: ' . $action]);
}
