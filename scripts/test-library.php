<?php
declare(strict_types=1);

// Exercise the actual catalog helpers without changing the installed database.
$source = file_get_contents(__DIR__ . '/../manager/index.php');
$start = strpos($source, 'function registerVideo(');
$end = strpos($source, '$error = null;', $start);
eval(substr($source, $start, $end - $start));
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
check(videoTitle('  Introdução   ao cadastro  ') === 'Introdução ao cadastro', 'Normalize title');
check(videoTitle(str_repeat('á', 150)) === str_repeat('á', 150), 'Unicode limit');
foreach ([null, [], '', '   ', str_repeat('á', 151), "bad\0title", "\xff"] as $invalid) {
    $rejected = false;
    try { videoTitle($invalid); } catch (RuntimeException) { $rejected = true; }
    check($rejected, 'Invalid title accepted');
}
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE videos (title TEXT NOT NULL, filename TEXT UNIQUE)');
registerVideo($db, 'old.mp4');
registerVideo($db, 'new.mp4', 'Introdução ao cadastro');
registerVideo($db, 'new.mp4');
check($db->query("SELECT title FROM videos WHERE filename = 'new.mp4'")->fetchColumn() === 'Introdução ao cadastro', 'Sync overwrote title');
check($db->query("SELECT title FROM videos WHERE filename = 'old.mp4'")->fetchColumn() === 'old.mp4', 'Legacy import failed');
echo "PASS: title validation, Unicode, persistence and legacy synchronization\n";

if (in_array('--preview', $argv, true)) {
    function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function playbackQuery(array $video, string $key): string { return 'id=' . $video['id']; }
    $videos = [];
    foreach (['Introdução ao Zeus PRO', 'Como cadastrar um produto', 'Configuração de usuários e permissões', 'Relatórios de vendas', 'Compras e estoque', '<Teste> de título seguro & acentuação'] as $index => $title) {
        $videos[] = ['id' => $index + 1, 'title' => $title, 'filename' => 'gravacao-' . $index . '.mp4', 'created_at' => '2026-11-13'];
    }
    $playbackKey = ''; $baseUrl = 'https://example.invalid/embed?';
    $adminName = 'Administrador'; $error = $message = $freshRecovery = null;
    $_SESSION = ['csrf' => 'preview']; $nonce = 'preview';
    ob_start();
    eval('?>' . substr($source, strpos($source, '<!DOCTYPE html>')));
    $html = ob_get_clean();
    $html = str_replace(['/access.css?v=20261113', '/img/', 'https://streaming.zeuspro.com.br/media.php?'], ['../manager/access.css', '../img/', 'https://example.invalid/media?'], $html);
    if (!is_dir(__DIR__ . '/../artifacts')) mkdir(__DIR__ . '/../artifacts');
    file_put_contents(__DIR__ . '/../artifacts/library-preview.html', $html);
    echo "Preview fixture rendered in artifacts/library-preview.html\n";
}
