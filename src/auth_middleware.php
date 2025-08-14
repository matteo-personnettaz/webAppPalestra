// src/auth_middleware.php
<?php
declare(strict_types=1);

function require_firebase_user(): string {
  $auth = require __DIR__ . '/firebase_admin.php';
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) fail('Missing bearer token', 401);
  $idToken = trim($m[1]);
  try {
    $verified = $auth->verifyIdToken($idToken);
    return $verified->claims()->get('sub'); // uid
  } catch (Throwable $e) {
    fail('Invalid token: '.$e->getMessage(), 401);
  }
}
