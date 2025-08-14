// src/firebase_admin.php
<?php
declare(strict_types=1);
use Kreait\Firebase\Factory;

require __DIR__ . '/vendor/autoload.php';

$factory = new Factory();

// 1) Se c'è un path nel env, usalo (secret montato o var)
$path = getenv('FIREBASE_CREDENTIALS_PATH');
if ($path && is_readable($path)) {
  return $factory->withServiceAccount($path)->createAuth();
}

// 2) Fallback: cerca il file locale (sviluppo in locale)
$local = __DIR__ . '/secure/service-account.json';
if (is_readable($local)) {
  return $factory->withServiceAccount($local)->createAuth();
}

// 3) Ultimo tentativo: Application Default Credentials (se presenti)
return $factory->createAuth();
