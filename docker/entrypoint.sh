#!/bin/sh
set -eu
if [ "${1:-}" = 'php-fpm' ]; then
  php bin/console migrations:migrate --no-interaction
  php bin/console app:user --no-interaction
fi
exec "$@"
