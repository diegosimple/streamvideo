# Identidade visual do Streamvideo

Escopo: portal de gestão com linguagem de catálogo (inspiração Netflix) e telas de acesso alinhadas à marca Zeus PRO.

## Marca e cores

- Logo: `img/imgLogo.png` (sem filtros que distorçam a marca); favicon `img/icone-erp.ico`.
- Primárias: `#0057BA`, `#0076DD`.
- Destaques: `#17BB2E` (sucesso), `#EFE778` (alerta / ênfase).
- Fundo escuro de catálogo: `#050b16` → `#071428`, cards `#0d1b33`.

## Hierarquia

- Login / setup / recuperar: cartão central escuro, faixa gradiente da marca, uma ação principal.
- Biblioteca autenticada: topbar fixa (logo + conta + sair), hero com upload, grade de capas estilo catálogo.
- Conta: menu “Trocar senha” no próprio portal; gera nova chave de recuperação exibida uma vez.
- Player (`embed.php`): foco no vídeo, fundo preto, favicon da marca.

## Tipografia e estados

- Tipografia do sistema (Segoe UI / system-ui / Arial).
- Erro e sucesso em banners próximos à ação; capa do card usa o 1º quadro via `media.php` assinado.
- Responsivo: grade fluida, upload empilhado no celular, menu de conta em painel absoluto.

## Segurança visual

- Senhas e chaves em campos ocultos; conteúdo variável escapado.
- Estilos em `/access.css` (`style-src 'self'`); scripts do painel com nonce CSP.
- Links assinados continuam válidos por 7 dias; revogação e exclusão permanecem nas ações do card.
