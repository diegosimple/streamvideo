<?php
declare(strict_types=1);
require __DIR__ . '/playback.php';
$playback = authorizedPlayback();
$nonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'nonce-$nonce'; base-uri 'none'; form-action 'none'; frame-ancestors *");
$mediaUrl = '/media.php?' . $playback['query'];
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zeus PRO · Vídeo</title>
<link rel="icon" href="/img/icone-erp.ico" type="image/x-icon">
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    html, body { margin: 0; width: 100%; height: 100%; background: #070B14; color: white; }
    video { display: block; width: 100%; height: 100%; object-fit: contain; }
</style></head><body>
<video controls playsinline preload="metadata" controlslist="nodownload" disablepictureinpicture src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') ?>">
    Seu navegador não suporta a reprodução deste vídeo.
</video>
</body></html>
