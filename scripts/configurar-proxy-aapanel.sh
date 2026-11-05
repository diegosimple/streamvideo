#!/usr/bin/env bash
set -Eeuo pipefail

DOMAIN="${STREAMVIDEO_DOMAIN:-streaming.zeuspro.com.br}"
HTTPD="${AAPANEL_HTTPD:-/www/server/apache/bin/httpd}"
SERVICE="${AAPANEL_HTTPD_SERVICE:-/etc/init.d/httpd}"
VHOST="/www/server/panel/vhost/apache/${DOMAIN}.conf"
EXTENSION_DIR="/www/server/panel/vhost/apache/extension/${DOMAIN}"
CONFIG="${EXTENSION_DIR}/streamvideo-proxy.conf"

if [[ "${EUID}" -ne 0 ]]; then
    echo "Execute este script como root (por exemplo: sudo bash scripts/configurar-proxy-aapanel.sh)." >&2
    exit 1
fi
if [[ ! -x "${HTTPD}" || ! -x "${SERVICE}" || ! -f "${VHOST}" ]]; then
    echo "Apache/aaPanel não foi localizado. Confira os caminhos e a variável AAPANEL_HTTPD_SERVICE." >&2
    exit 1
fi
if ! grep -Fq "IncludeOptional ${EXTENSION_DIR}/*.conf" "${VHOST}"; then
    echo "O VirtualHost não inclui a pasta de extensões esperada: ${EXTENSION_DIR}" >&2
    exit 1
fi
if ! "${HTTPD}" -M 2>&1 | grep -q 'proxy_module'; then
    echo "O módulo proxy_module do Apache não está ativo." >&2
    exit 1
fi
if ! "${HTTPD}" -M 2>&1 | grep -q 'proxy_http_module'; then
    echo "O módulo proxy_http_module do Apache não está ativo." >&2
    exit 1
fi

mkdir -p "${EXTENSION_DIR}"
temporary="$(mktemp "${EXTENSION_DIR}/.streamvideo-proxy.XXXXXX")"
backup=""
cleanup() { rm -f -- "${temporary}"; }
trap cleanup EXIT
cat > "${temporary}" <<'APACHE'
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:8080/ connectiontimeout=5 timeout=600
ProxyPassReverse / http://127.0.0.1:8080/
APACHE
chmod 0644 "${temporary}"

if [[ -e "${CONFIG}" ]]; then
    backup="${CONFIG}.bak.$(date +%Y%m%d%H%M%S)"
    cp -a -- "${CONFIG}" "${backup}"
fi
mv -f -- "${temporary}" "${CONFIG}"

if ! "${HTTPD}" -t; then
    if [[ -n "${backup}" ]]; then cp -a -- "${backup}" "${CONFIG}"; else rm -f -- "${CONFIG}"; fi
    echo "Configuração inválida; a versão anterior foi restaurada." >&2
    exit 1
fi

if ! "${SERVICE}" restart; then
    if [[ -n "${backup}" ]]; then cp -a -- "${backup}" "${CONFIG}"; else rm -f -- "${CONFIG}"; fi
    "${HTTPD}" -t && "${SERVICE}" restart || true
    echo "O Apache não reiniciou; a configuração anterior foi restaurada quando possível." >&2
    exit 1
fi

echo "Proxy aplicado para ${DOMAIN} -> http://127.0.0.1:8080"
if [[ -n "${backup}" ]]; then echo "Cópia anterior: ${backup}"; fi
