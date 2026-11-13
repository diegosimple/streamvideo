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
function registerVideo(PDO $db, string $filename, ?string $title = null): void {
    $db->prepare('INSERT OR IGNORE INTO videos (title, filename) VALUES (?, ?)')->execute([$title ?? $filename, $filename]);
}
function videoTitle(mixed $value): string {
    if (!is_string($value) || !preg_match('//u', $value)) {
        throw new RuntimeException('Informe um título válido para o vídeo.');
    }
    $title = trim(preg_replace('/\s+/u', ' ', $value));
    if ($title === '' || preg_match_all('/./us', $title) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $title)) {
        throw new RuntimeException('O título deve ter entre 1 e 150 caracteres.');
    }
    return $title;
}
$error = null;
$jsonUpload = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload'
    && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
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
            $title = videoTitle($_POST['title'] ?? null);
            $file = $_FILES['video_file'] ?? null;
            $extension = validateVideoUpload($file);
            $stem = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo(basename($file['name']), PATHINFO_FILENAME));
            $filename = substr($stem, 0, 100) . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $videoDir . $filename)) {
                throw new RuntimeException('Não foi possível salvar o vídeo. Verifique as permissões da pasta videos.');
            }
            chmod($videoDir . $filename, 0644);
            registerVideo($db, $filename, $title);
            $_SESSION['message'] = 'Upload concluído.';
        } elseif (($_POST['action'] ?? '') === 'rename') {
            $title = videoTitle($_POST['title'] ?? null);
            $db->prepare('UPDATE videos SET title = ? WHERE id = ?')->execute([$title, (int) ($_POST['id'] ?? 0)]);
            $_SESSION['message'] = 'Título atualizado.';
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
        if ($jsonUpload) {
            unset($_SESSION['message']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        header('Location: /', true, 303);
        exit;
    } catch (RuntimeException $exception) {
        if (http_response_code() < 400) http_response_code(400);
        $error = $exception->getMessage();
        if ($jsonUpload) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR);
            exit;
        }
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
    <link rel="stylesheet" href="/access.css?v=20261113">
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
    <section class="hero library-intro">
        <div>
            <p class="eyebrow">ZEUS PRO · SUA VIDEOTECA</p>
            <h1>Biblioteca de vídeos</h1>
            <p>Aulas, tutoriais e gravações. Todo o seu conteúdo em um só lugar.</p>
        </div>
        <div class="hero-meta">
            <span class="chip"><strong><?= count($videos) ?></strong> título(s)</span>
            <a class="btn btn-primary" href="#new-video">＋ Adicionar vídeo</a>
        </div>
    </section>
    <details class="upload-panel" id="new-video" <?= $error && ($_POST['action'] ?? '') === 'upload' ? 'open' : '' ?>>
        <summary>Adicionar vídeos <span>MP4 ou MOV · até 500 MB por vídeo</span></summary>
        <form class="upload-rail" id="upload-form" method="post" enctype="multipart/form-data">
            <div class="upload-fields">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="upload">
                <label for="video_title">Título do vídeo</label>
                <input id="video_title" type="text" name="title" maxlength="150" placeholder="Ex.: Como cadastrar um produto" value="<?= escape(($_POST['action'] ?? '') === 'upload' && is_string($_POST['title'] ?? null) ? $_POST['title'] : '') ?>" aria-describedby="title-help" required>
                <p id="title-help">Use um nome fácil de reconhecer e buscar na biblioteca.</p>
            </div>
            <div class="file-zone">
                <label for="video_file">Selecione os vídeos</label>
                <input id="video_file" type="file" name="video_file" accept="video/mp4,video/quicktime,.mp4,.mov" required>
                <p id="file-status" role="status">O título aparece no catálogo; o arquivo original fica nos detalhes.</p>
            </div>
            <div id="upload-queue" hidden></div>
            <button type="submit" class="btn-primary">Enviar vídeo</button>
            <p id="upload-status" role="status" hidden></p>
            <a id="refresh-library" class="btn btn-ghost" href="/" hidden>Ver vídeos na biblioteca</a>
        </form>
    </details>

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
            <h2>Seu catálogo</h2>
            <p id="result-count" role="status"><?= count($videos) ?> vídeo(s) · mais recentes primeiro</p>
        </div>
        <div class="catalog-search">
            <label class="sr-only" for="video-search">Buscar por título ou arquivo</label>
            <input type="search" id="video-search" placeholder="Buscar por título ou arquivo…" autocomplete="off">
        </div>
    </div>

    <section class="video-grid" aria-label="Catálogo de vídeos">
        <?php foreach ($videos as $video):
            $query = playbackQuery($video, $playbackKey);
            $playerUrl = $baseUrl . $query;
            $mediaUrl = 'https://streaming.zeuspro.com.br/media.php?' . $query;
            $label = $video['title'] !== '' ? $video['title'] : $video['filename'];
            ?>
            <article class="video-card" data-title="<?= escape($label) ?>" data-filename="<?= escape($video['filename']) ?>">
                <a class="video-poster" href="<?= escape($playerUrl) ?>" target="_blank" rel="noopener" aria-label="<?= escape('Assistir: ' . $label . ' (abre em nova aba)') ?>">
                    <span class="poster-placeholder" aria-hidden="true">ZEUS<span>PRO STREAMING</span></span>
                    <video muted playsinline preload="none" data-src="<?= escape($mediaUrl) ?>" aria-hidden="true" tabindex="-1"></video>
                    <span class="poster-badge"><?= escape(strtoupper(pathinfo($video['filename'], PATHINFO_EXTENSION))) ?></span>
                    <div class="poster-play" aria-hidden="true"><span>▶</span></div>
                </a>
                <div class="video-body">
                    <h3 title="<?= escape($label) ?>"><?= escape($label) ?></h3>
                    <div class="meta">Adicionado em <?= escape(date('d/m/Y', strtotime($video['created_at']))) ?></div>
                    <div class="video-actions">
                        <button type="button" class="btn btn-primary btn-sm" data-copy="<?= (int) $video['id'] ?>">Copiar link</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-embed="<?= (int) $video['id'] ?>">Copiar iframe</button>
                    </div>
                    <details class="video-details">
                        <summary>Gerenciar vídeo</summary>
                        <form method="post" class="rename-form">
                            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="id" value="<?= (int) $video['id'] ?>">
                            <label for="title_<?= (int) $video['id'] ?>">Título</label>
                            <input id="title_<?= (int) $video['id'] ?>" name="title" type="text" value="<?= escape($label) ?>" maxlength="150" required>
                            <button class="btn btn-ghost btn-sm" type="submit">Salvar título</button>
                        </form>
                    <div class="meta">Arquivo · <?= escape($video['filename']) ?></div>
                    <label class="sr-only" for="url_<?= (int) $video['id'] ?>">URL do player</label>
                    <input type="text" id="url_<?= (int) $video['id'] ?>" value="<?= escape($playerUrl) ?>" readonly>
                    <div class="video-actions">
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
                    </details>
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
    <div id="search-empty" class="empty-state" hidden><strong>Nenhum vídeo encontrado</strong>Tente outro título ou nome de arquivo.</div>

    <p id="copy-status" role="status" aria-live="polite"></p>
    <textarea id="embed-code" class="sr-only" aria-label="Código iframe para copiar" rows="4" cols="60" readonly></textarea>
</main>
<script nonce="<?= escape($nonce) ?>">
const uploadPanel = document.getElementById('new-video');
document.querySelector('a[href="#new-video"]').addEventListener('click', () => {
    uploadPanel.open = true;
    document.getElementById('video_file').focus();
});
const fileInput = document.getElementById('video_file');
const uploadForm = document.getElementById('upload-form');
const queueElement = document.getElementById('upload-queue');
const uploadButton = uploadForm.querySelector('button[type="submit"]');
const singleTitle = document.getElementById('video_title');
singleTitle.required = false;
singleTitle.disabled = true;
singleTitle.closest('.upload-fields').hidden = true;
fileInput.multiple = true;
uploadButton.textContent = 'Enviar vídeos';
document.getElementById('file-status').textContent = 'Selecione um ou mais arquivos e confira o título de cada vídeo antes de enviar.';
let uploadQueue = [];
let uploading = false;
window.addEventListener('beforeunload', event => {
    if (uploading) { event.preventDefault(); event.returnValue = ''; }
});
fileInput.addEventListener('change', () => {
    queueElement.replaceChildren();
    uploadQueue = [...fileInput.files].map((file, index) => {
        const row = document.createElement('div'); row.className = 'upload-item';
        const label = document.createElement('label'); label.htmlFor = `batch-title-${index}`;
        label.textContent = `Título do vídeo ${index + 1}`;
        const title = document.createElement('input'); title.type = 'text'; title.id = label.htmlFor;
        title.required = true; title.maxLength = 150;
        title.value = file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 150);
        const detail = document.createElement('p'); detail.textContent = `${file.name} · ${(file.size / 1024 / 1024).toFixed(1)} MB`;
        const result = document.createElement('p'); result.setAttribute('role', 'status');
        const invalid = file.size > 500 * 1024 * 1024 || file.size < 16 || !/\.(mp4|mov)$/i.test(file.name);
        result.textContent = invalid ? 'Arquivo inválido: selecione MP4 ou MOV de até 500 MB.' : 'Pronto para enviar';
        row.append(label, title, detail, result); queueElement.append(row);
        return { file, title, result, invalid, done: false, uncertain: false };
    });
    queueElement.hidden = !uploadQueue.length;
    uploadButton.disabled = !uploadQueue.length || uploadQueue.some(item => item.invalid);
    uploadButton.textContent = 'Enviar vídeos';
    document.getElementById('file-status').textContent = `${uploadQueue.length} vídeo(s) selecionado(s). Confira os títulos abaixo.`;
    document.getElementById('upload-status').hidden = true;
});
uploadForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (uploading || !uploadQueue.length || uploadQueue.some(item => item.invalid)) return;
    for (const item of uploadQueue.filter(item => !item.done && !item.uncertain)) {
        item.title.setCustomValidity(item.title.value.trim() ? '' : 'Informe o título do vídeo.');
        if (!item.title.reportValidity()) {
            item.title.addEventListener('input', () => item.title.setCustomValidity(''), { once: true });
            return;
        }
    }
    uploading = true; fileInput.disabled = true; uploadButton.disabled = true;
    uploadQueue.forEach(item => item.title.disabled = true);
    const message = document.getElementById('upload-status');
    message.hidden = false;
    for (const [index, item] of uploadQueue.entries()) {
        if (item.done || item.uncertain) continue;
        uploadButton.textContent = `Enviando ${index + 1} de ${uploadQueue.length}…`;
        message.textContent = 'Aguarde a conclusão antes de fechar esta página.';
        item.result.textContent = 'Enviando e processando vídeo…';
        const data = new FormData();
        data.append('action', 'upload'); data.append('csrf', uploadForm.elements.csrf.value);
        data.append('title', item.title.value.trim()); data.append('video_file', item.file);
        try {
            const response = await fetch('/', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('unexpected-response');
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                item.result.textContent = result.error || 'Não foi possível enviar este vídeo.';
                continue;
            }
            item.done = true; item.result.textContent = 'Enviado com sucesso';
        } catch {
            item.uncertain = true;
            item.result.textContent = 'Não foi possível confirmar o envio. Confira a biblioteca antes de enviar este arquivo novamente.';
            break;
        }
    }
    uploading = false; fileInput.disabled = false;
    uploadQueue.forEach(item => item.title.disabled = item.done || item.uncertain);
    const sent = uploadQueue.filter(item => item.done).length;
    const pending = uploadQueue.filter(item => !item.done && !item.uncertain).length;
    message.textContent = `${sent} de ${uploadQueue.length} vídeo(s) enviado(s).${pending ? ' Você pode tentar enviar os pendentes novamente.' : ''}`;
    uploadButton.textContent = pending ? 'Enviar pendentes' : 'Envio finalizado';
    uploadButton.disabled = !pending;
    document.getElementById('refresh-library').hidden = false;
});
const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
const cards = [...document.querySelectorAll('.video-card')];
document.getElementById('video-search').addEventListener('input', event => {
    const query = normalize(event.target.value.trim());
    let count = 0;
    cards.forEach(card => {
        card.hidden = !normalize(card.dataset.title + ' ' + card.dataset.filename).includes(query);
        if (!card.hidden) count++;
    });
    document.getElementById('result-count').textContent = `${count} vídeo(s)${query ? ' encontrado(s)' : ' · mais recentes primeiro'}`;
    document.getElementById('search-empty').hidden = !query || count > 0;
    const empty = document.querySelector('.video-grid > .empty-state');
    if (empty) empty.hidden = Boolean(query);
});
const loadPoster = video => {
    video.src = video.dataset.src;
    video.preload = 'metadata';
    video.addEventListener('loadedmetadata', () => {
        if (Number.isFinite(video.duration) && video.duration > 0) video.currentTime = Math.min(1, video.duration / 2);
    }, { once: true });
    video.addEventListener('seeked', () => video.classList.add('poster-ready'), { once: true });
};
if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) { loadPoster(entry.target); observer.unobserve(entry.target); }
    }), { rootMargin: '120px' });
    document.querySelectorAll('video[data-src]').forEach(video => observer.observe(video));
} else document.querySelectorAll('video[data-src]').forEach(loadPoster);
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
        input.closest('details').open = true;
        input.select();
        flash('Pressione Ctrl+C para copiar a URL.');
    }
}
</script>
</body>
</html>
