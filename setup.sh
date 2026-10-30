#!/bin/sh
set -eu
cd "$(dirname "$0")"
command -v docker >/dev/null 2>&1 || { echo 'Install and start Docker Desktop / Docker Engine with Compose v2.'; exit 1; }
docker info >/dev/null 2>&1 || { echo 'Docker daemon is unavailable. Start Docker Desktop and retry.'; exit 1; }
if [ ! -f .env ]; then
  cp .env.example .env
fi
if grep -q '^JWT_SECRET=replace_' .env; then
  secret=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
  sed "s/^JWT_SECRET=.*/JWT_SECRET=$secret/" .env > .env.tmp
  mv .env.tmp .env
  chmod 600 .env
fi
docker compose build app nginx
docker compose up -d --no-build --wait --wait-timeout 240
echo 'Ready. Open the APP_PORT from .env (default http://localhost:8080).'
echo 'Default login: admin@example.com / CatalogDemo2026! (unless changed in .env before first start).'
