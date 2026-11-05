<?php
if (!isset($screen, $nonce)) { http_response_code(404); exit; }
$titles = ['login' => 'Acesse seus vídeos', 'setup' => 'Configure seu acesso', 'recover' => 'Recupere seu acesso'];
$descriptions = [
    'login' => 'Entre para organizar, publicar e compartilhar seus vídeos.',
    'setup' => 'Crie o administrador que vai gerenciar sua biblioteca de vídeos.',
    'recover' => 'Use a chave guardada na criação da conta para definir uma nova senha.',
];
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escape($titles[$screen]) ?> · Zeus PRO</title>
<link rel="icon" href="/img/icone-erp.ico" type="image/x-icon">
<link rel="stylesheet" href="/access.css">
</head><body><main class="access-card">
<img class="brand" src="/img/imgLogo.png" alt="Zeus PRO" width="400" height="100">
<p class="eyebrow">Streaming · Zeus PRO</p>
<?php if ($recoveryCode): ?>
    <h1>Guarde sua chave</h1>
    <p>Seu acesso foi atualizado. Salve esta chave em um local seguro: ela permite redefinir sua senha e será exibida apenas uma vez.</p>
    <code class="recovery-code" id="recovery-code"><?= escape($recoveryCode) ?></code>
    <p class="notice">Esta chave substitui qualquer chave de recuperação anterior. Não compartilhe e guarde antes de sair desta página.</p>
    <a class="primary" href="/">Guardei minha chave. Entrar</a>
<?php elseif ($setupClosed): ?>
    <h1>Acesso já configurado</h1><p>O administrador já foi criado. A configuração inicial está encerrada.</p>
    <a class="primary" href="/">Ir para o login</a><p class="links"><a href="/recuperar">Recuperar minha senha</a></p>
<?php else: ?>
    <h1><?= escape($titles[$screen]) ?></h1><p><?= escape($descriptions[$screen]) ?></p>
    <?php if ($error): ?><p class="notice" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <form method="post" action="<?= $screen === 'setup' ? '/setup' : ($screen === 'recover' ? '/recuperar' : '/') ?>">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="hidden" name="action" value="<?= escape($screen) ?>">
        <?php if ($screen === 'setup'): ?>
            <label for="setup_key">Chave de instalação</label><input id="setup_key" type="password" name="setup_key" maxlength="64" autocomplete="off" required>
            <p class="help">Use a chave obtida no terminal da VPS, conforme o README.</p>
        <?php endif; ?>
        <label for="username">Usuário</label><input id="username" name="username" autocomplete="username" maxlength="80" required value="<?= escape(input('username')) ?>">
        <?php if ($screen === 'recover'): ?>
            <label for="recovery_key">Chave de recuperação</label><input id="recovery_key" type="password" name="recovery_key" maxlength="200" autocomplete="off" required>
        <?php endif; ?>
        <label for="password"><?= $screen === 'recover' ? 'Nova senha' : 'Senha' ?></label>
        <input id="password" type="password" name="password" autocomplete="<?= $screen === 'login' ? 'current-password' : 'new-password' ?>" maxlength="<?= $screen === 'login' ? '1024' : '72' ?>" required>
        <?php if ($screen !== 'login'): ?>
            <p class="help">Use uma senha com pelo menos 8 caracteres. Limite de 72 bytes; acentos ocupam mais de um byte.</p>
            <label for="password_confirmation">Confirme a senha</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" maxlength="72" required>
        <?php endif; ?>
        <button type="submit"><?= ['login' => 'Entrar', 'setup' => 'Criar administrador', 'recover' => 'Redefinir senha'][$screen] ?></button>
    </form>
    <p class="links"><a href="<?= $screen === 'login' ? '/recuperar' : '/' ?>"><?= $screen === 'login' ? 'Esqueci minha senha' : 'Voltar para o login' ?></a></p>
<?php endif; ?>
<p class="footer">Sua biblioteca de vídeos, em um só lugar.</p>
</main></body></html>
