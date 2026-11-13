# Identidade visual do Streamvideo

Escopo: portal de gestão com linguagem de catálogo (inspiração Netflix) e telas de acesso alinhadas à marca Zeus PRO.

## Marca e cores

- Logo: `img/imgLogo.png` (sem filtros que distorçam a marca); favicon `img/icone-erp.ico`.
- Primárias: `#0057BA`, `#0076DD`.
- Destaques: `#17BB2E` (sucesso), `#EFE778` (alerta / ênfase).
- Fundo escuro de catálogo: `#050b16` → `#071428`, cards `#0d1b33`.

## Hierarquia

- Login / setup / recuperar: cartão central escuro, faixa gradiente da marca, uma ação principal.
- Biblioteca autenticada: topbar fixa, introdução editorial, envio recolhível, busca e grade de capas 16:9. Fundo carvão neutro, com azul Zeus PRO nas ações.
- Direção confirmada pelo pedido: catálogo de streaming inspirado na Netflix, preservando marca e stack PHP. Implementação direta no produto existente.
- Título obrigatório de até 150 caracteres no envio; busca imediata por título ou arquivo, ignorando acentos e caixa. Vídeos antigos podem ser renomeados nos detalhes.
- Capas carregadas sob demanda com quadro real do vídeo e fallback da videoteca. Clique abre o player; copiar link/iframe são ações visíveis, renomear/revogar/apagar ficam em “Gerenciar vídeo”.
- Envio mostra arquivo e tamanho selecionados, valida limite e informa estado de envio. Busca sem resultado tem mensagem própria. Grade de uma coluna no celular e foco de teclado visível.
- Conta: menu “Trocar senha” no próprio portal; gera nova chave de recuperação exibida uma vez.
- Player (`embed.php`): foco no vídeo, fundo preto, favicon da marca.

## Tipografia e estados

- Tipografia do sistema (Segoe UI / system-ui / Arial).
- Erro e sucesso em banners próximos à ação; capa do card usa o 1º quadro via `media.php` assinado.
- Responsivo: grade fluida, upload empilhado no celular, menu de conta em painel absoluto.

## Segurança visual

- Senhas e chaves em campos ocultos; conteúdo variável escapado.
- Estilos em `/access.css` (`style-src 'self'`); scripts do painel com nonce CSP.
- Links assinados não expiram; revogação e exclusão permanecem nas ações do card.
