#!/bin/bash
set -eo pipefail

php /app/docker-entrypoint.php

while true; do
  echo "Running anime update cycle at $(date)"

  if ! php /app/artisan sidecar:anime-update \
      --airing-months="${ANIME_SIDECAR_AIRING_MONTHS:-1}"; then
    echo "Anime update cycle failed at $(date)"
  fi

  echo "Sleeping for 24 hours..."
  sleep 86400
done