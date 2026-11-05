<?php
declare(strict_types=1);

function playbackKey(bool $create = false): string {
    $path = '/var/lib/streamvideo/playback.key';
    if ($create) {
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível configurar o player.');
        try {
            if (!is_file($path)) {
                $temporary = tempnam(dirname($path), 'key-');
                if (!$temporary || file_put_contents($temporary, bin2hex(random_bytes(32))) === false) {
                    throw new RuntimeException('Não foi possível configurar o player.');
                }
                chmod($temporary, 0600);
                if (!rename($temporary, $path)) throw new RuntimeException('Não foi possível configurar o player.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $key = is_file($path) ? trim((string) file_get_contents($path)) : '';
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new RuntimeException('Player indisponível.');
    return $key;
}

function playbackSignature(int $id, int $version, string $key, ?int $expires = null): string {
    $expiry = $expires === null ? 'permanent' : (string) $expires;
    return hash_hmac('sha256', "video:$id:$expiry:$version", $key);
}

function playbackQuery(array $video, string $key): string {
    return http_build_query([
        'id' => (int) $video['id'],
        'signature' => playbackSignature((int) $video['id'], (int) $video['token_version'], $key),
    ]);
}

function authorizedPlayback(): array {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    try {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expires = isset($_GET['expires'])
            ? filter_var($_GET['expires'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        $signature = $_GET['signature'] ?? '';
        if (!$id || ($expires !== null && (!$expires || $expires < time())) || !is_string($signature)
            || !preg_match('/\A[a-f0-9]{64}\z/', $signature)) throw new RuntimeException();
        $db = new PDO('sqlite:/var/lib/streamvideo/database.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $db->prepare('SELECT id, filename, token_version FROM videos WHERE id = ?');
        $stmt->execute([$id]);
        $video = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$video || !hash_equals(playbackSignature($id, (int) $video['token_version'], playbackKey(), $expires), $signature)) {
            throw new RuntimeException();
        }
        $filename = $video['filename'];
        if ($filename !== basename($filename) || str_contains($filename, '\\')
            || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['mp4', 'mov'], true)
            || is_link('/videos/' . $filename) || !is_file('/videos/' . $filename)) throw new RuntimeException();
        $query = ['id' => $id];
        if ($expires !== null) $query['expires'] = $expires;
        $query['signature'] = $signature;
        return ['video' => $video, 'query' => http_build_query($query)];
    } catch (Throwable $exception) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Link inválido, expirado ou revogado. Solicite um novo link ao responsável pelo vídeo.');
    }
}
