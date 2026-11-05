<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/upload.php';
require __DIR__ . '/playback.php';
$playbackKey = playbackKey(true);
$videoDir = '/videos/';
$baseUrl = 'https://streaming.zeuspro.com.br/embed.php?';
$db = new PDO('sqlite:/var/lib/streamvideo/database.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec('CREATE TABLE IF NOT EXISTS videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    filename TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');
$columns = $db->query('PRAGMA table_info(videos)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('token_version', $columns, true)) {
    $db->exec('ALTER TABLE videos ADD COLUMN token_version INTEGER NOT NULL DEFAULT 0');
}
function registerVideo(PDO $db, string $filename): void {
    $db->prepare('INSERT OR IGNORE INTO videos (title, filename) VALUES (?, ?)')->execute([$filename, $filename]);
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validCsrf()) {
            throw new RuntimeException('Sessão inválida ou envio acima do limite. Atualize a página e tente novamente.');
        }
        if (($_POST['action'] ?? '') === 'delete') {
            $stmt = $db->prepare('SELECT filename FROM videos WHERE id = ?');
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $video = $stmt->fetch();
            if ($video) {
                $path = $videoDir . basename($video['filename']);
                if (is_link($path) || (is_file($path) && !unlink($path))) {
                    throw new RuntimeException('Não foi possível remover o arquivo.');
                }
                $db->prepare('DELETE FROM videos WHERE id = ?')->execute([(int) $_POST['id']]);
            }
            $_SESSION['message'] = 'Vídeo removido.';
        } elseif (($_POST['action'] ?? '') === 'revoke') {
            $db->prepare('UPDATE videos SET token_version = token_version + 1 WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            $_SESSION['message'] = 'Links anteriores revogados. Copie o novo link ou iframe.';
        } elseif (($_POST['action'] ?? '') === 'upload') {
            $file = $_FILES['video_file'] ?? null;
            $extension = validateVideoUpload($file);
            $stem = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo(basename($file['name']), PATHINFO_FILENAME));
            $filename = substr($stem, 0, 100) . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $videoDir . $filename)) {
                throw new RuntimeException('Não foi possível salvar o vídeo. Verifique as permissões da pasta videos.');
            }
            chmod($videoDir . $filename, 0644);
            registerVideo($db, $filename);
            $_SESSION['message'] = 'Upload concluído.';
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        header('Location: /', true, 303);
        exit;
    } catch (RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    }
}
foreach (array_merge(glob($videoDir . '*.mp4') ?: [], glob($videoDir . '*.mov') ?: []) as $file) {
    if (is_file($file) && !is_link($file)) registerVideo($db, basename($file));
}
$videos = $db->query('SELECT * FROM videos ORDER BY id DESC')->fetchAll();
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestor de Vídeos VOD - Zeus PRO</title>
    <link rel="icon" href="/img/icone-erp.ico" type="image/x-icon">
    <style nonce="<?= escape($nonce) ?>">
        body { font-family: Arial, sans-serif; background: #0A2D6E; color: white; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: #070B14; padding: 20px; border-radius: 8px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #1B5FC1; }
        button { padding: 8px 12px; background: #1B5FC1; color: white; border-radius: 4px; border: none; cursor: pointer; }
        .danger { background: #EAA20C; color: #000; }
        .upload-box { background: #0D1424; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        input[type="text"] { min-width: 240px; width: 100%; box-sizing: border-box; padding: 8px; background: #0A2D6E; color: white; border: 1px solid #1B5FC1; }
        .message { color: #EAA20C; }
        td form { display: inline; }
        .brand-bar { display: flex; justify-content: space-between; align-items: center; gap: 20px; background: #fff; border-radius: 8px; padding: 16px 20px; margin-bottom: 28px; }
        .brand-bar img { display: block; width: 240px; max-width: 65%; height: auto; }
        h1 { font-size: 26px; margin-bottom: 24px; }
        textarea { max-width: 100%; }
        @media (max-width: 600px) { body { padding: 10px; } .container { padding: 16px; } .brand-bar { padding: 12px; } h1 { font-size: 22px; } }
    </style>
</head>
<body>
<main class="container">
    <header class="brand-bar">
    <img src="/img/imgLogo.png" alt="Zeus PRO" width="400" height="100">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Sair</button>
    </form>
    </header>
    <h1>Biblioteca de vídeos</h1>
    <?php if ($error || $message): ?><p class="message" role="status"><?= escape($error ?? $message) ?></p><?php endif; ?>
    <div class="upload-box">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="upload">
            <label for="video_file">Enviar vídeo MP4 ou MOV (até 500 MB):</label>
            <input id="video_file" type="file" name="video_file" accept="video/mp4,video/quicktime,.mp4,.mov" required>
            <button type="submit">Enviar vídeo</button>
        </form>
    </div>
    <p>Gravações aparecem após a conversão para MP4. Atualize a página para sincronizar.</p>
    <p>Links e iframes são válidos por 7 dias a partir da geração. Qualquer pessoa com o link pode assistir nesse período.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Arquivo</th><th>Link do player (7 dias)</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($videos as $video): ?>
            <tr>
                <td><?= (int) $video['id'] ?></td>
                <td><?= escape($video['filename']) ?></td>
                <td><input type="text" id="url_<?= (int) $video['id'] ?>" aria-label="URL de <?= escape($video['filename']) ?>" value="<?= escape($baseUrl . playbackQuery($video, $playbackKey)) ?>" readonly></td>
                <td>
                    <button type="button" data-copy="<?= (int) $video['id'] ?>">Copiar</button>
                    <button type="button" data-embed="<?= (int) $video['id'] ?>">Copiar iframe</button>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                        <button type="submit">Revogar links</button>
                    </form>
                    <form method="post" data-delete>
                        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                        <button type="submit" class="danger">Apagar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$videos): ?><tr><td colspan="4">Nenhum vídeo disponível. Envie um MP4/MOV ou inicie uma transmissão.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <p id="copy-status" role="status"></p>
    <textarea id="embed-code" aria-label="Código iframe para copiar" rows="4" cols="60" readonly hidden></textarea>
</main>
<script nonce="<?= escape($nonce) ?>">
document.querySelectorAll('[data-copy]').forEach(button => {
    button.addEventListener('click', () => copyUrl(button.dataset.copy));
});
document.querySelectorAll('[data-embed]').forEach(button => {
    button.addEventListener('click', async () => {
        const frame = document.createElement('iframe');
        frame.width = '560'; frame.height = '315';
        frame.src = document.getElementById('url_' + button.dataset.embed).value;
        frame.title = 'Zeus PRO video player'; frame.allow = 'fullscreen';
        frame.referrerPolicy = 'no-referrer'; frame.setAttribute('allowfullscreen', '');
        const output = document.getElementById('embed-code');
        output.value = frame.outerHTML; output.hidden = false;
        try {
            await navigator.clipboard.writeText(output.value);
            document.getElementById('copy-status').textContent = 'Iframe copiado.';
        } catch {
            output.select();
            document.getElementById('copy-status').textContent = 'Pressione Ctrl+C para copiar o iframe selecionado.';
        }
    });
});
document.querySelectorAll('[data-delete]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!confirm('Deseja excluir este vídeo?')) event.preventDefault();
    });
});
async function copyUrl(id) {
    const input = document.getElementById('url_' + id);
    try {
        await navigator.clipboard.writeText(input.value);
        document.getElementById('copy-status').textContent = 'URL copiada.';
    } catch {
        input.select();
        document.getElementById('copy-status').textContent = 'Pressione Ctrl+C para copiar a URL selecionada.';
    }
}
</script>
</body>
</html>
