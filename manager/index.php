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
$freshRecovery = null;
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
        } elseif (($_POST['action'] ?? '') === 'password') {
            $currentPassword = input('current_password');
            $newPassword = input('password');
            $confirm = input('password_confirmation');
            if ($newPassword !== $confirm) {
                throw new RuntimeException('A confirmação da senha não corresponde.');
            }
            if (strlen($currentPassword) > 1024 || str_contains($currentPassword, "\0")
                || !password_verify($currentPassword, $admin['hash'])) {
                if (!consumeAuthAttempt()) {
                    http_response_code(429);
                    header('Retry-After: 900');
                    throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.');
                }
                http_response_code(401);
                throw new RuntimeException('Senha atual incorreta.');
            }
            validateCredentials($admin['username'], $newPassword);
            $result = withAdminLock(function () use ($admin, $newPassword, $currentPassword): array {
                $current = readAdmin();
                if ($current === null || !hash_equals($current['username'], $admin['username'])
                    || !password_verify($currentPassword, $current['hash'])) {
                    http_response_code(401);
                    throw new RuntimeException('Senha atual incorreta.');
                }
                $result = newAdmin($current['username'], $newPassword);
                saveAdmin($result['admin']);
                return $result;
            });
            clearAuthAttempts();
            $newFingerprint = hash('sha256', $result['admin']['username'] . $result['admin']['hash']);
            session_regenerate_id(true);
            $_SESSION['auth'] = $newFingerprint;
            $_SESSION['last_seen'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $_SESSION['recovery_code'] = $result['code'];
            $_SESSION['message'] = 'Senha atualizada com sucesso.';
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        header('Location: /', true, 303);
        exit;
    } catch (RuntimeException $exception) {
        if (http_response_code() < 400) http_response_code(400);
        $error = $exception->getMessage();
    }
}
foreach (array_merge(glob($videoDir . '*.mp4') ?: [], glob($videoDir . '*.mov') ?: []) as $file) {
    if (is_file($file) && !is_link($file)) registerVideo($db, basename($file));
}
$videos = $db->query('SELECT * FROM videos ORDER BY id DESC')->fetchAll();
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
if (isset($_SESSION['recovery_code'])) {
    $freshRecovery = $_SESSION['recovery_code'];
    unset($_SESSION['recovery_code']);
}
$adminName = $admin['username'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Biblioteca · Streaming Zeus PRO</title>
    <link rel="icon" href="/img/icone-erp.ico" type="image/x-icon">
    <link rel="stylesheet" href="/access.css">
</head>
<body class="panel-body">
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand-lockup">
            <img src="/img/imgLogo.png" alt="Zeus PRO" width="400" height="100">
            <span class="tag">Streaming</span>
        </div>
        <div class="top-actions">
            <details class="account-menu">
                <summary class="btn btn-ghost btn-sm" aria-label="Conta">Conta · <?= escape($adminName) ?></summary>
                <div class="account-panel">
                    <h2>Trocar senha</h2>
                    <p>Altere a senha sem sair do portal. Uma nova chave de recuperação será gerada.</p>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="password">
                        <label for="current_password">Senha atual</label>
                        <input id="current_password" type="password" name="current_password" autocomplete="current-password" maxlength="1024" required>
                        <label for="new_password">Nova senha</label>
                        <input id="new_password" type="password" name="password" autocomplete="new-password" maxlength="72" required>
                        <label for="password_confirmation">Confirme a nova senha</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" maxlength="72" required>
                        <div class="panel-actions">
                            <button type="submit" class="btn-primary">Salvar senha</button>
                        </div>
                    </form>
                </div>
            </details>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-ghost btn-sm">Sair</button>
            </form>
        </div>
    </div>
</header>

<main class="panel-shell">
    <section class="hero">
        <div>
            <h1>Biblioteca de vídeos</h1>
            <p>Gerencie aulas e gravações com visual de catálogo. Gere links assinados, incorpore no Zeus e revogue acessos quando precisar.</p>
        </div>
        <div class="hero-meta">
            <span class="chip"><strong><?= count($videos) ?></strong> título(s)</span>
            <span class="chip">Links válidos por <strong>7 dias</strong></span>
            <span class="chip">MP4 / MOV até <strong>500 MB</strong></span>
        </div>
        <form class="upload-rail" method="post" enctype="multipart/form-data">
            <div>
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="upload">
                <label for="video_file">Adicionar título à biblioteca</label>
                <input id="video_file" type="file" name="video_file" accept="video/mp4,video/quicktime,.mp4,.mov" required>
            </div>
            <button type="submit" class="btn-primary">Enviar vídeo</button>
        </form>
    </section>

    <?php if ($freshRecovery): ?>
        <aside class="recovery-banner" role="status">
            <h2>Nova chave de recuperação</h2>
            <p>Guarde esta chave agora. Ela substitui a anterior e só aparece uma vez após a troca de senha.</p>
            <code id="recovery-code"><?= escape($freshRecovery) ?></code>
        </aside>
    <?php endif; ?>

    <?php if ($error || $message): ?>
        <p class="flash<?= $error ? ' is-error' : '' ?>" role="status"><?= escape($error ?? $message) ?></p>
    <?php endif; ?>

    <div class="section-head">
        <div>
            <h2>Catálogo</h2>
            <p>Gravações ao vivo aparecem após a conversão para MP4. Atualize a página para sincronizar.</p>
        </div>
    </div>

    <section class="video-grid" aria-label="Catálogo de vídeos">
        <?php foreach ($videos as $video):
            $query = playbackQuery($video, $playbackKey);
            $playerUrl = $baseUrl . $query;
            $mediaUrl = 'https://streaming.zeuspro.com.br/media.php?' . $query;
            $label = $video['title'] !== '' ? $video['title'] : $video['filename'];
            ?>
            <article class="video-card" data-filename="<?= escape($video['filename']) ?>">
                <div class="video-poster">
                    <video muted playsinline preload="metadata" src="<?= escape($mediaUrl) ?>" aria-hidden="true" tabindex="-1"></video>
                    <span class="poster-badge">ID <?= (int) $video['id'] ?></span>
                    <div class="poster-play" aria-hidden="true"><span>▶</span></div>
                </div>
                <div class="video-body">
                    <h3 title="<?= escape($label) ?>"><?= escape($label) ?></h3>
                    <div class="meta">Arquivo · <?= escape($video['filename']) ?></div>
                    <label class="sr-only" for="url_<?= (int) $video['id'] ?>">URL do player</label>
                    <input type="text" id="url_<?= (int) $video['id'] ?>" value="<?= escape($playerUrl) ?>" readonly>
                    <div class="video-actions">
                        <button type="button" class="btn btn-primary btn-sm" data-copy="<?= (int) $video['id'] ?>">Copiar link</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-embed="<?= (int) $video['id'] ?>">Copiar iframe</button>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                            <button type="submit" class="btn btn-warn btn-sm span-2">Revogar links</button>
                        </form>
                        <form method="post" data-delete>
                            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm span-2">Apagar</button>
                        </form>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$videos): ?>
            <div class="empty-state">
                <strong>Nenhum título ainda</strong>
                Envie um MP4/MOV ou inicie uma transmissão RTMP para popular o catálogo.
            </div>
        <?php endif; ?>
    </section>

    <p id="copy-status" role="status" aria-live="polite"></p>
    <textarea id="embed-code" class="sr-only" aria-label="Código iframe para copiar" rows="4" cols="60" readonly></textarea>
</main>
<script nonce="<?= escape($nonce) ?>">
const status = document.getElementById('copy-status');
function flash(message) {
    status.textContent = message;
    status.classList.add('is-on');
    clearTimeout(flash._t);
    flash._t = setTimeout(() => status.classList.remove('is-on'), 2200);
}
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
            flash('Iframe copiado.');
        } catch {
            output.select();
            flash('Pressione Ctrl+C para copiar o iframe.');
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
        flash('URL copiada.');
    } catch {
        input.select();
        flash('Pressione Ctrl+C para copiar a URL.');
    }
}
</script>
</body>
</html>
