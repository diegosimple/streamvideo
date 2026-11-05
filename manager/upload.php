<?php
declare(strict_types=1);

function validateVideoUpload(mixed $file): string {
    $message = 'Envie um vídeo MP4 ou MOV válido de até 500 MB.';
    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
        || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException($message);
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $size = filesize($file['tmp_name']);
    if (!in_array($extension, ['mp4', 'mov'], true) || $size === false || $size < 16 || $size > 500 * 1024 * 1024) {
        throw new RuntimeException($message);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['video/mp4', 'video/quicktime'], true)) throw new RuntimeException($message);

    // Read the actual container and require a video stream; filename and HTTP MIME are not trusted.
    // Array arguments avoid shell interpolation. Protocols and execution time are restricted.
    $process = proc_open([
        '/usr/bin/timeout', '20', '/usr/bin/ffprobe', '-v', 'error',
        '-protocol_whitelist', 'file', '-format_whitelist', 'mov',
        '-probesize', '5000000', '-analyzeduration', '5000000',
        '-select_streams', 'v:0', '-show_entries', 'format=format_name:stream=codec_type,width,height',
        '-of', 'json', $file['tmp_name'],
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Não foi possível validar o vídeo.');
    $output = stream_get_contents($pipes[1], 65536);
    fclose($pipes[1]);
    $status = proc_close($process);
    $probe = json_decode($output ?: '', true);
    $stream = $probe['streams'][0] ?? [];
    if ($status !== 0 || ($stream['codec_type'] ?? '') !== 'video'
        || ($stream['width'] ?? 0) < 1 || ($stream['height'] ?? 0) < 1
        || !str_contains($probe['format']['format_name'] ?? '', 'mov')) {
        throw new RuntimeException($message);
    }
    return $extension;
}
