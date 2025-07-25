<?php
// router.php

// Ottieni solo il path, es. '/videos/demo.mp4' o '/api.php'
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Mappa su public/
$publicFile = __DIR__ . '/public' . $uri;

// Se esiste un file statico in public/, lascia che il server lo serva direttamente
if ($uri !== '/api.php' && file_exists($publicFile)) {
    return false;  // il built‑in server servirà $publicFile
}

// Altrimenti ridirigi tutto a api.php
require_once __DIR__ . '/api.php';
