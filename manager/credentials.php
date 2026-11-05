<?php
declare(strict_types=1);

const ADMIN_PATH = '/var/lib/streamvideo/admin.json';
const SETUP_KEY_PATH = '/var/lib/streamvideo/setup.key';

function readAdmin(): ?array {
    if (!is_file(ADMIN_PATH)) return null;
    $admin = json_decode((string) file_get_contents(ADMIN_PATH), true);
    if (!is_array($admin) || !is_string($admin['username'] ?? null) || !is_string($admin['hash'] ?? null)) {
        throw new RuntimeException('Configuração de acesso indisponível. Consulte o administrador da VPS.');
    }
    return $admin;
}

function withAdminLock(callable $operation): mixed {
    $lock = fopen(ADMIN_PATH . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível atualizar o acesso.');
    try { return $operation(); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

function saveAdmin(array $admin): void {
    $temporary = tempnam(dirname(ADMIN_PATH), 'admin-');
    if (!$temporary) throw new RuntimeException('Não foi possível salvar o acesso.');
    try {
        chmod($temporary, 0600);
        if (file_put_contents($temporary, json_encode($admin, JSON_THROW_ON_ERROR)) === false || !rename($temporary, ADMIN_PATH)) {
            throw new RuntimeException('Não foi possível salvar o acesso.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function validateCredentials(string $username, string $password): void {
    if (!preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/', $username)) {
        throw new RuntimeException('Use um usuário de 3 a 80 caracteres: letras, números, ponto, hífen ou sublinhado.');
    }
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RuntimeException('A senha deve ter entre 12 e 72 bytes. Use uma frase longa e exclusiva.');
    }
}

function recoveryHash(string $code): string {
    return hash('sha256', strtoupper(preg_replace('/[\s-]/', '', $code)));
}

function newAdmin(string $username, string $password): array {
    validateCredentials($username, $password);
    $code = implode('-', str_split(strtoupper(bin2hex(random_bytes(32))), 8));
    return [
        'admin' => ['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'recovery_hash' => recoveryHash($code)],
        'code' => $code,
    ];
}

function installationKey(): string {
    return withAdminLock(function (): string {
        if (readAdmin() !== null) throw new RuntimeException('O administrador já foi configurado.');
        if (!is_file(SETUP_KEY_PATH)) {
            $temporary = tempnam(dirname(SETUP_KEY_PATH), 'setup-');
            if (!$temporary) throw new RuntimeException('Não foi possível gerar a chave de instalação.');
            chmod($temporary, 0600);
            if (file_put_contents($temporary, bin2hex(random_bytes(32))) === false || !rename($temporary, SETUP_KEY_PATH)) {
                if (is_file($temporary)) unlink($temporary);
                throw new RuntimeException('Não foi possível gerar a chave de instalação.');
            }
        }
        return trim((string) file_get_contents(SETUP_KEY_PATH));
    });
}

// Persistent, shared by login, setup and recovery; sessions cannot reset the limit.
function authAttempts(): PDO {
    $db = new PDO('sqlite:/var/lib/streamvideo/auth.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('CREATE TABLE IF NOT EXISTS attempts (ip TEXT NOT NULL, attempted_at INTEGER NOT NULL)');
    return $db;
}

function consumeAuthAttempt(): bool {
    $db = authAttempts();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM attempts WHERE attempted_at < ?')->execute([time() - 900]);
        $query = $db->prepare('SELECT COUNT(*) FROM attempts WHERE ip = ?');
        $query->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        $allowed = (int) $query->fetchColumn() < 10 && (int) $db->query('SELECT COUNT(*) FROM attempts')->fetchColumn() < 100;
        if ($allowed) $db->prepare('INSERT INTO attempts VALUES (?, ?)')->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown', time()]);
        $db->exec('COMMIT');
        return $allowed;
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function clearAuthAttempts(): void {
    authAttempts()->prepare('DELETE FROM attempts WHERE ip = ?')->execute([$_SERVER['REMOTE_ADDR'] ?? 'unknown']);
}
