<?php declare(strict_types=1);

/**
 * Questo file:
 * - NON deve avere BOM e niente spazi prima di <?php
 * - Non fa echo/print: deve solo "return" l'istanza di Auth
 */

// Carica l'autoload Composer dovunque si trovi (locale e container)
if (!class_exists(\Kreait\Firebase\Factory::class)) {
    $candidates = [
        __DIR__ . '/vendor/autoload.php',            // esecuzione locale: vendor in src/
        __DIR__ . '/../vendor/autoload.php',         // progetto root/vendor
        dirname(__DIR__) . '/vendor/autoload.php',   // progetto root/vendor (variante)
        '/var/www/html/vendor/autoload.php',         // container run
    ];
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            break;
        }
    }
}

use Kreait\Firebase\Factory;

$factory = new Factory();

/**
 * 1) Preferisci ENV FIREBASE_CREDENTIALS_JSON (Cloud Run secret)
 *    - se è JSON puro -> decodifica
 *    - se è un path -> usa quel file
 */
$raw = getenv('FIREBASE_CREDENTIALS_JSON') ?: '';
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $factory = $factory->withServiceAccount($decoded);
    } elseif (is_file($raw)) {
        $factory = $factory->withServiceAccount($raw);
    }
} else {
    // 2) Path esplicito via ENV
    $path = getenv('FIREBASE_CREDENTIALS_PATH') ?: '';
    if ($path && is_readable($path)) {
        $factory = $factory->withServiceAccount($path);
    } else {
        // 3) Fallback locale per dev
        $local = __DIR__ . '/secure/service-account.json';
        if (is_readable($local)) {
            $factory = $factory->withServiceAccount($local);
        }
        // 4) Altrimenti ADC
    }
}

// opzionale: forzare Project ID via ENV
$projectId = getenv('FIREBASE_PROJECT_ID');
if ($projectId) {
    $factory = $factory->withProjectId($projectId);
}

return $factory->createAuth();
