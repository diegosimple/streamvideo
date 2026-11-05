<?php
declare(strict_types=1);
require __DIR__ . '/playback.php';
$playback = authorizedPlayback();
$filename = $playback['video']['filename'];
header('Content-Type: ' . (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mov' ? 'video/quicktime' : 'video/mp4'));
header('Content-Disposition: inline');
header('X-Accel-Redirect: /protected-videos/' . rawurlencode($filename));
