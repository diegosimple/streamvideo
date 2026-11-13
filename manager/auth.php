<?php
declare(strict_types=1);
require_once __DIR__ . '/credentials.php';
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => getenv('MANAGER_HTTPS') === '1', 'samesite' => 'Strict', 'path' => '/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$nonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; connect-src 'self'; media-src 'self' https://streaming.zeuspro.com.br; style-src 'self' 'nonce-$nonce'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function validCsrf(): bool { return is_string($_POST['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $_POST['csrf']); }
function redirectTo(string $path): never { header('Location: ' . $path, true, 303); exit; }
function redirectHome(): never { redirectTo('/'); }
function input(string $name): string { return is_string($_POST[$name] ?? null) ? $_POST[$name] : ''; }

try { $admin = readAdmin(); }
catch (RuntimeException $exception) { http_response_code(503); exit(escape($exception->getMessage())); }
$configured = $admin !== null;
$fingerprint = $configured ? hash('sha256', $admin['username'] . $admin['hash']) : '';
$authenticated = $configured && is_string($_SESSION['auth'] ?? null)
    && hash_equals($fingerprint, $_SESSION['auth']) && time() - ($_SESSION['last_seen'] ?? 0) < 1800;
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
$screen = match ($path) { '/setup' => 'setup', '/recuperar' => 'recover', default => 'login' };
$error = null;
$recoveryCode = null;
$setupClosed = $screen === 'setup' && $configured;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($screen, ['setup', 'recover'], true)
    && ($_SESSION['recovery_screen'] ?? null) === $screen && isset($_SESSION['recovery_code'])) {
    $recoveryCode = $_SESSION['recovery_code'];
    unset($_SESSION['recovery_code'], $_SESSION['recovery_screen']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (input('action') === 'logout' && $screen === 'login') {
        if (!validCsrf()) { http_response_code(403); exit('Sessão inválida.'); }
        $_SESSION = [];
        session_regenerate_id(true);
        redirectHome();
    }
    if ($screen !== 'login' || !$authenticated) {
        try {
            if ($setupClosed) { http_response_code(409); throw new RuntimeException('O primeiro administrador já foi criado. Entre ou recupere seu acesso.'); }
            if (!validCsrf()) { http_response_code(403); throw new RuntimeException('Sessão inválida. Atualize a página e tente novamente.'); }
            $expectedAction = ['login' => 'login', 'setup' => 'setup', 'recover' => 'recover'][$screen];
            if (input('action') !== $expectedAction) { http_response_code(403); throw new RuntimeException('Acesso não autorizado. Entre para continuar.'); }
            if (!consumeAuthAttempt()) {
                http_response_code(429); header('Retry-After: 900');
                throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.');
            }
            if ($screen === 'login') {
                $password = input('password');
                $passwordOk = $configured && strlen($password) <= 1024 && !str_contains($password, "\0") && password_verify($password, $admin['hash']);
                if (!$passwordOk || !hash_equals($admin['username'], input('username'))) {
                    http_response_code(401); throw new RuntimeException('Usuário ou senha inválidos.');
                }
                clearAuthAttempts();
                session_regenerate_id(true);
                $_SESSION = ['auth' => $fingerprint, 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
                redirectHome();
            }
            if (input('password') !== input('password_confirmation')) {
                http_response_code(400); throw new RuntimeException('A confirmação da senha não corresponde.');
            }
            validateCredentials(input('username'), input('password'));
            $result = withAdminLock(function () use ($screen): array {
                $current = readAdmin();
                if ($screen === 'setup') {
                    if ($current !== null) { http_response_code(409); throw new RuntimeException('O primeiro administrador já foi criado.'); }
                    $key = is_file(SETUP_KEY_PATH) ? trim((string) file_get_contents(SETUP_KEY_PATH)) : '';
                    if ($key === '' || !hash_equals($key, trim(input('setup_key')))) {
                        http_response_code(403); throw new RuntimeException('Chave de instalação inválida. Consulte o README para obter a chave na VPS.');
                    }
                } elseif ($current === null || !is_string($current['recovery_hash'] ?? null)
                    || !hash_equals($current['username'], input('username'))
                    || strlen(input('recovery_key')) > 200
                    || !hash_equals($current['recovery_hash'], recoveryHash(input('recovery_key')))) {
                    http_response_code(403); throw new RuntimeException('Usuário ou chave de recuperação inválidos.');
                }
                $result = newAdmin(input('username'), input('password'));
                saveAdmin($result['admin']);
                if (is_file(SETUP_KEY_PATH)) unlink(SETUP_KEY_PATH);
                return $result;
            });
            clearAuthAttempts();
            session_regenerate_id(true);
            $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'recovery_code' => $result['code'], 'recovery_screen' => $screen];
            redirectTo($screen === 'setup' ? '/setup' : '/recuperar');
        } catch (RuntimeException $exception) {
            if (http_response_code() < 400) http_response_code(400);
            $error = $exception->getMessage();
        }
    }
}

if (!$configured && $screen !== 'setup') redirectTo('/setup');
if ($screen !== 'login' || !$authenticated) {
    if (!$authenticated) unset($_SESSION['auth'], $_SESSION['last_seen']);
    if ($setupClosed && !$recoveryCode) http_response_code(409);
    require __DIR__ . '/access-page.php';
    exit;
}
$_SESSION['last_seen'] = time();
