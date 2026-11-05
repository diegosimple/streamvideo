# streamvideo

Streaming RTMP/HLS e hospedagem de MP4/MOV da Zeus PRO para https://streaming.zeuspro.com.br, com painel autenticado e player incorporável por iframe.

## Instalação na VPS

Requer Git, Docker Engine com Compose e DNS apontando para a VPS. Antes de instalar, envie as alterações locais ao GitHub com `git push`.

Conecte-se à VPS por SSH e clone a branch `main` na pasta do domínio:

```bash
mkdir -p /www/wwwroot
git clone --branch main https://github.com/diegosimple/streamvideo.git /www/wwwroot/streaming.zeuspro.com.br
cd /www/wwwroot/streaming.zeuspro.com.br
```

A pasta de destino deve estar ausente ou vazia. Se o aaPanel já criou arquivos nela, preserve-os e organize a pasta antes de clonar. Se o repositório for privado, autentique-se no GitHub com um token ou uma chave SSH autorizada; não coloque o token na URL do clone.

Depois do clone, prepare a pasta de vídeos e inicie os serviços:

```bash
cd /www/wwwroot/streaming.zeuspro.com.br
mkdir -p videos
sudo chown 33:33 videos
sudo chmod 775 videos
docker compose build rtmp-server && docker compose build video-manager && docker compose up -d --no-build
docker compose ps
```

Os serviços escrevem em videos como www-data (UID 33). Não é necessário chmod 777. O SQLite, o hash da senha e a chave de assinatura ficam no volume manager-data, fora da raiz pública. O build verifica PDO SQLite e fileinfo e instala FFprobe para verificar o conteúdo dos uploads.

### Proxy HTTPS no Apache do aaPanel

O Apache instalado pelo aaPanel roda no host da VPS, fora dos contêineres. Por isso a configuração dele fica em um script versionado do projeto, não no Dockerfile ou no Compose. Depois de iniciar os contêineres e criar o site/SSL no aaPanel, execute na pasta clonada:

```bash
bash scripts/configurar-proxy-aapanel.sh
```

O script verifica o VirtualHost específico do domínio e os módulos Apache de proxy, grava uma extensão persistente em `extension/`, valida a configuração e só reinicia o Apache se a validação passar. Se o Apache usa caminhos diferentes, informe-os, por exemplo, `AAPANEL_HTTPD=/caminho/httpd AAPANEL_HTTPD_SERVICE=/caminho/httpd-service bash scripts/configurar-proxy-aapanel.sh`.

Depois verifique `curl -k -sS -o /dev/null -w 'HTTPS: %{http_code}\n' https://streaming.zeuspro.com.br/setup`; o esperado é `200`. Em produção HTTPS, defina `MANAGER_HTTPS=1` no `.env` antes de iniciar o painel para usar cookies de sessão `Secure`:

```bash
if [ -f .env ] && grep -q '^MANAGER_HTTPS=' .env; then
    sed -i 's/^MANAGER_HTTPS=.*/MANAGER_HTTPS=1/' .env
else
    printf '%s\n' 'MANAGER_HTTPS=1' >> .env
fi
docker compose up -d --no-build video-manager
```

Para atualizar uma instalação existente após publicar alterações no GitHub:

```bash
cd /www/wwwroot/streaming.zeuspro.com.br
git pull --ff-only origin main
docker compose build rtmp-server && docker compose build video-manager && docker compose up -d --no-build --force-recreate
docker compose ps
```

Se o `git pull` falhar, resolva o erro antes de executar os comandos seguintes. A recriação aplica também mudanças nas configurações montadas dos serviços e preserva os vídeos, o `.env` e o volume de dados. Ela interrompe transmissões em andamento; faça a atualização fora do horário das lives.

### Se a VPS travar ou o terminal desconectar durante o build

O FFmpeg instala várias dependências. Construir os dois serviços simultaneamente pode aumentar o pico de uso de memória, CPU e disco. Os comandos acima constroem um serviço por vez e só iniciam os contêineres se ambos os builds terminarem com sucesso. Isso reduz a concorrência, mas não garante que os recursos da VPS sejam suficientes.

“Tentando reconectar” no terminal do aaPanel não confirma reinicialização da VPS. Depois de reconectar, verifique:

```bash
uptime -s
free -h
swapon --show
df -h / /var/lib/docker
sudo journalctl -k -b --no-pager -n 150
sudo journalctl -k -b -1 --no-pager -n 150
```

Compare o horário de inicialização com o momento da falha. Nos logs, procure `Out of memory`, `oom-kill`, `Killed process`, erros de disco ou `panic`. `-b -1` consulta a inicialização anterior; ela pode não estar disponível se o journal não for persistente. Ausência de logs anteriores não prova que não houve falha. Se a VPS realmente reiniciou sem registrar uma causa, confira também os eventos e gráficos do provedor.

Antes de repetir o build, confirme que a execução anterior terminou; não inicie outra enquanto ela ainda estiver trabalhando. Se houver falta de memória, avalie RAM/swap conforme os recursos e o espaço livre da VPS, ou construa as imagens em outra máquina compatível e distribua-as por um registro. Não remova volumes ou arquivos de vídeos para tentar corrigir o build.

Referências: [paralelismo do Docker Compose](https://docs.docker.com/reference/cli/docker/compose/#configuring-parallelism) e [consulta de inicializações com journalctl](https://www.freedesktop.org/software/systemd/man/latest/journalctl.html).

## Primeiro acesso em /setup

Depois de iniciar os contêineres, obtenha a chave de instalação no terminal da VPS:

```bash
docker compose exec -T --user www-data video-manager php /opt/streamvideo/setup-key.php
```

Abra `https://streaming.zeuspro.com.br/setup`, informe essa chave, escolha o usuário e confirme a senha. Sem administrador, o login redireciona automaticamente para `/setup`. A chave comprova acesso à VPS e impede que outra pessoa assuma o cadastro inicial apenas por conhecer a URL.

Ao concluir, **guarde a chave de recuperação exibida na tela** em um gerenciador de senhas. Ela aparece somente uma vez. O setup é fechado após a criação da conta e a chave de instalação é removida. Não existe senha padrão ou cadastro público de novos administradores. Usuários aceitam 3–80 letras, números, ponto, hífen ou sublinhado; senhas aceitam pelo menos 8 caracteres e até 72 bytes.

No computador local, as mesmas telas estão em `http://localhost:8081/setup` e `http://localhost:8081/recuperar`. O ambiente local usa HTTP; na VPS use HTTPS e `MANAGER_HTTPS=1`.

## Recuperar a senha em /recuperar

No login, clique em **Esqueci minha senha**, ou acesse `https://streaming.zeuspro.com.br/recuperar`. Informe o usuário, a chave de recuperação e a nova senha com confirmação. O sistema invalida as sessões antigas e a chave utilizada, e mostra uma **nova chave de recuperação** para guardar. Uma chave usada não pode ser reutilizada. A recuperação também exige CSRF e está sujeita ao limite de tentativas; não permite redefinir a senha apenas pelo nome do usuário.

Não há envio de e-mail: a prova de acesso é a chave de recuperação. Se perdeu a chave, se a conta foi criada por uma versão antiga ou se precisa restaurar o acesso pelo terminal, execute no Bash da VPS:

```bash
read -rsp 'Senha do administrador (mínimo 8 caracteres; máximo 72 bytes): ' ADMIN_PASSWORD; echo
printf '%s\n' "$ADMIN_PASSWORD" | docker compose exec -T --user www-data video-manager php /opt/streamvideo/setup-admin.php admin
unset ADMIN_PASSWORD
```

Troque `admin` pelo usuário desejado. Esse comando administrativo cria/substitui a conta, invalida sessões anteriores e mostra uma nova chave de recuperação. Guarde a chave que ele imprimir. A senha e a chave de recuperação são armazenadas somente como hashes no volume privado.

## Domínio e aaPanel

1. Crie o site streaming.zeuspro.com.br como Pure/Static.
2. Configure Let's Encrypt e Force HTTPS.
3. Configure o proxy reverso para http://127.0.0.1:8080, preservando o caminho da requisição.
4. Configure `client_max_body_size 510m` e timeout de pelo menos 600 segundos no proxy para aceitar os uploads.
5. Desative cache para o painel, `/setup`, `/recuperar`, `/hls/`, `/embed.php` e `/media.php`. Preserve cookies, parâmetros da URL e requisições `Range`.
6. Na VPS, acrescente `MANAGER_HTTPS=1` ao `.env` e execute `docker compose up -d` para marcar os cookies de autenticação como Secure.

As portas 8080 e 8081 ficam acessíveis somente no localhost da VPS. No domínio principal, `/` mostra o login/painel, `/setup` cria o primeiro administrador e `/recuperar` redefine a senha com a chave de recuperação. O player fica em `/embed.php` e usa o link assinado gerado no painel. As antigas URLs `/videos/arquivo.mp4` permanecem bloqueadas. A playlist ao vivo continua disponível em `/hls/`, para players HLS como VLC.

Se 8080 estiver ocupada, defina `VIDEO_HTTP_PORT=18080` em um arquivo `.env` local e ajuste o proxy para essa porta. O `.env` não é versionado. Nesta estação, a validação usou 18080.

## Painel privado

O painel já está disponível pelo domínio principal, com login. Se preferir acesso local por SSH, mantenha o túnel aberto:

```bash
ssh -N -L 8081:127.0.0.1:8081 usuario@IP_DA_VPS
```

Acesse http://localhost:8081 e faça login. O painel usa visual de catálogo (tons `#0057BA`, `#0076DD`, `#17BB2E`, `#EFE778`), aceita MP4 e MOV até 500 MB, sincroniza gravações ao atualizar a página e permite copiar links, copiar iframes, revogar links e excluir vídeos. No menu **Conta** você troca a senha sem sair do portal; a troca exige a senha atual e exibe uma **nova chave de recuperação** uma única vez. Todas as alterações exigem sessão autenticada e token CSRF.

Um subdomínio exclusivo para o painel é opcional: configure SSL, Force HTTPS e proxy para `http://127.0.0.1:8081`, com os mesmos limites de upload e sem cache. Use somente HTTPS em produção; `MANAGER_HTTPS=1` impede login via HTTP por marcar o cookie como Secure. Para desenvolvimento local ou uso exclusivamente por túnel HTTP, mantenha `MANAGER_HTTPS=0` (padrão). Não exponha 8081 diretamente na internet.

O login renova o identificador de sessão e expira após 30 minutos de inatividade. Login, setup e recuperação compartilham o limite de 10 tentativas por origem em 15 minutos, com teto global de 100. Por trás do proxy, visitantes podem compartilhar a mesma origem para esse limite; cabeçalhos enviados pelo cliente não são usados para contorná-lo.

## Identidade visual

Login, setup, recuperação e painel usam `img/imgLogo.png` como logo horizontal e `img/icone-erp.ico` como favicon. As imagens são montadas como somente leitura. Mantenha a pasta `img` junto ao projeto ao copiar os arquivos para a VPS. As telas de acesso usam um cartão claro sobre o azul da marca, com campos adaptados para celular.

## Incorporar e proteger vídeos

No painel, clique em **Copiar iframe** e cole o código no seu site. O player usa os controles nativos do navegador e oculta o botão de download quando o navegador suporta essa opção. Exemplo de estrutura (substitua pela URL completa copiada):

```html
<iframe width="560" height="315"
  src="URL_ASSINADA_COPIADA_DO_PAINEL"
  title="Zeus PRO video player"
  allow="fullscreen" referrerpolicy="no-referrer" allowfullscreen></iframe>
```

Cada link tem assinatura HMAC e validade de **7 dias** a partir da geração. Alterar ID, validade ou assinatura invalida o link. **Revogar links** bloqueia os links e iframes anteriores daquele vídeo; copie um novo para continuar compartilhando. Após 7 dias, gere e substitua o iframe. Quem receber o link pode assistir até ele expirar ou ser revogado, sem precisar da senha administrativa.

O Nginx entrega o arquivo por uma rota interna somente após validar a assinatura. O caminho real do arquivo não é divulgado no iframe, e acessos diretos a `/videos/` e `/protected-videos/` são bloqueados. Essa proteção se aplica a vídeos gravados; o HLS ao vivo permanece público. Links assinados não são DRM: quem pode assistir pode capturar a reprodução ou reutilizar o link durante sua validade. Revogação bloqueia novas requisições, sem apagar dados já recebidos pelo navegador. Evite registrar os parâmetros dos links em logs do proxy e não use cache público nessas rotas.

MOV é aceito e hospedado, mas a reprodução depende dos codecs suportados pelo navegador. Prefira MP4 com H.264/AAC para maior compatibilidade; o upload não transcodifica arquivos.

## Validação e proteção de entrada

- Extensões permitidas: somente `.mp4` e `.mov`, sem confiar no atributo `accept` do navegador.
- Validação do tamanho real, MIME detectado no servidor e contêiner/faixa de vídeo com FFprobe, com limite de tempo e protocolos restritos.
- HTML/texto renomeado para vídeo, extensões não permitidas e contêineres falsos sem vídeo são rejeitados.
- Nomes normalizados com sufixo aleatório, SQL parametrizado e escape HTML de textos e atributos.
- CSP com nonce, bloqueio de scripts inline não autorizados, cookies HttpOnly/SameSite e proteção CSRF.
- Arquivos de mídia ficam fora da raiz do Apache e nunca são executados como PHP/HTML; respostas usam tipos de mídia e `nosniff`.

Essas medidas reduzem os riscos; não substituem atualizações do Docker, PHP, Nginx e FFmpeg nem garantem ausência de vulnerabilidades em decodificadores.

## OBS e gravações

- Servidor: rtmp://streaming.zeuspro.com.br/live
- Chave: identificador simples, como evento (letras, números, hífen e sublinhado).
- Vídeo H.264, áudio AAC, keyframes a cada 2 segundos.
- Playlist: https://streaming.zeuspro.com.br/hls/evento.m3u8

O HLS é gerado diretamente em live, sem transcodificação. A chave identifica o canal e não é uma senha. Restrinja a porta TCP 1935 ao IP do transmissor no firewall de rede da VPS e confira as regras de encaminhamento do Docker. O domínio RTMP precisa resolver diretamente para a VPS, sem proxy HTTP de CDN.

Ao encerrar a live, FFmpeg remuxa FLV para MP4 com faststart. O MP4 só aparece após a conversão terminar. Falhas preservam o FLV sem publicar MP4 incompleto. Excluir MP4 pelo painel preserva o FLV original; planeje a retenção para controlar espaço em disco. Faça backup de videos e do volume manager-data.

## Verificação

```bash
docker compose config --quiet
docker compose exec video-manager php -l /var/www/html/index.php
docker compose exec rtmp-server nginx -t
docker compose logs --tail=100
```

Faça login, envie um MP4/MOV pequeno, abra o link assinado do player, teste o iframe e exclua pelo painel. Faça uma live de pelo menos 15 segundos, confira a playlist durante a transmissão e atualize o painel após encerrá-la. A disponibilidade externa depende de DNS, SSL, proxy e firewall.

Em ambiente **local de teste sem administrador configurado**, execute `python scripts/security-test.py --bootstrap`. Ele cria uma conta temporária pelo setup, testa a chave de instalação, o fechamento do cadastro, a recuperação com troca de chave, a invalidação de sessões antigas, login/logout, limitação de tentativas, CSRF, MP4/MOV, arquivos disfarçados, links assinados, revogação, bloqueio de arquivos diretos e exclusão, e remove o administrador temporário ao terminar. Não execute na VPS em uso. Exporte `VIDEO_HTTP_PORT` para o processo Python caso use uma porta diferente de 8080.

Validação local realizada: build de ambos os contêineres, sintaxe PHP/Nginx e testes de login/logout, CSRF, limite de tentativas, MP4/MOV, HTML disfarçado, assinaturas, revogação, acesso direto bloqueado e requisições parciais. Também foi validada uma transmissão sintética de 20 segundos com playlist HLS, conversão para MP4 e sincronização no painel. A gravação de demonstração permanece na pasta videos (ignorada pelo Git).

Referências: [nginx-rtmp](https://github.com/arut/nginx-rtmp-module/wiki/Directives), [PHP Apache](https://github.com/docker-library/php/blob/master/8.2/bookworm/apache/Dockerfile), [X-Accel-Redirect do Nginx](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_ignore_headers) e [validação de uploads da OWASP](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
