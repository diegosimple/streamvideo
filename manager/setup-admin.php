<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/credentials.php';
$username = $argv[1] ?? '';
$password = rtrim((string) fgets(STDIN), "\r\n");
if (!preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/', $username) || strlen($password) < 8 || strlen($password) > 72) {
    fwrite(STDERR, "Use um usuário de 3–80 letras/números/._- e uma senha de pelo menos 8 caracteres (máximo 72 bytes) via stdin.\n");
    exit(1);
}
try {
    $result = withAdminLock(function () use ($username, $password): array {
        $result = newAdmin($username, $password);
        saveAdmin($result['admin']);
        if (is_file(SETUP_KEY_PATH)) unlink(SETUP_KEY_PATH);
        return $result;
    });
    echo "Administrador configurado. Sessões anteriores serão invalidadas.\n";
    echo "Guarde a chave de recuperação (substitui a anterior): " . $result['code'] . "\n";
} catch (RuntimeException $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }
