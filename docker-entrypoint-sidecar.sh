#!/bin/bash
set -eo pipefail

# Run initialization
status=0
php /app/docker-entrypoint.php
status=$?

if [[ $status -ne 0 ]]; then
  echo "Failed to initialize sidecar"
  exit $status
fi

# Run the anime update sidecar command once daily
# Parameters:
# --airing-months: Number of months after airing end to stop updating (default: 1)
exec php /app/artisan sidecar:anime-update \
  --airing-months="${ANIME_SIDECAR_AIRING_MONTHS:-1}"
