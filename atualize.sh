#!/usr/bin/env bash
set -e

cd /www/wwwroot/streaming.zeuspro.com.br || exit 1

nohup bash -c '
  set -e
  trap '\''echo "=== ERRO NO DEPLOY ==="; date'\'' ERR
  echo "=== INÍCIO DO DEPLOY ==="
  date

  docker compose --progress plain build rtmp-server
  docker compose --progress plain build video-manager
  docker compose up -d --no-build --force-recreate

  docker compose ps
  echo "=== DEPLOY CONCLUÍDO ==="
  date
' > /root/deploy-streaming.log 2>&1 < /dev/null &

echo "Deploy iniciado. PID: $!"
echo "Acompanhe o log: tail -f /root/deploy-streaming.log"
