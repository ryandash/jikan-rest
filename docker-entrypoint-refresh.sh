#!/bin/sh

# Jikan Anime Refresh Sidecar
# This script refreshes anime data on a schedule
# It refreshes anime that are currently airing, recently finished (within 1 month), or not yet aired

set -e

# Load environment from .env file using proper sourcing
if [ -f /app/docker/config/.env.compose ]; then
  set -a
  . /app/docker/config/.env.compose
  set +a
fi

# Read Docker secrets and set them as environment variables
# Laravel uses __FILE suffix to indicate file-based secrets
if [ -f /run/secrets/db_username ]; then
  export DB_USERNAME=$(cat /run/secrets/db_username)
fi

if [ -f /run/secrets/db_password ]; then
  export DB_PASSWORD=$(cat /run/secrets/db_password)
fi

if [ -f /run/secrets/db_admin_username ]; then
  export DB_ADMIN_USERNAME=$(cat /run/secrets/db_admin_username)
fi

if [ -f /run/secrets/db_admin_password ]; then
  export DB_ADMIN_PASSWORD=$(cat /run/secrets/db_admin_password)
fi

if [ -f /run/secrets/redis_password ]; then
  export REDIS_PASSWORD=$(cat /run/secrets/redis_password)
fi

if [ -f /run/secrets/typesense_api_key ]; then
  export TYPESENSE_API_KEY=$(cat /run/secrets/typesense_api_key)
fi

APP_URL=${APP_URL:-"http://jikan_rest:8080"}
DELAY=${DELAY:-3}
REFRESH_INTERVAL=${REFRESH_INTERVAL:-86400}  # Default: 24 hours

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1"
}

log "Starting Jikan Anime Refresh Sidecar"
log "APP_URL: $APP_URL"
log "DELAY: $DELAY"
log "REFRESH_INTERVAL: $REFRESH_INTERVAL seconds"
log "DB_HOST: $DB_HOST"
log "DB_DATABASE: $DB_DATABASE"
log ""

# Wait for the main API service to be healthy before starting
log "Waiting for Jikan REST API to be ready..."
attempt=0
max_attempts=30
while [ $attempt -lt $max_attempts ]; do
    if wget --spider -q "$APP_URL/v4/anime/1" 2>/dev/null; then
        log "Jikan REST API is ready!"
        break
    fi
    attempt=$((attempt + 1))
    log "  Attempt $attempt/$max_attempts - waiting for API to be ready..."
    sleep 10
done

if [ $attempt -eq $max_attempts ]; then
    log "ERROR: Could not connect to Jikan REST API at $APP_URL"
    exit 1
fi

# Main loop
while true; do
    log "Starting anime refresh cycle..."
    log ""

    cd /app

    # Run the refresh command
    if php artisan indexer:anime-refresh --delay=$DELAY; then
        log "Anime refresh completed successfully"
    else
        log "ERROR: Anime refresh failed"
    fi

    log ""
    log "Next refresh in $REFRESH_INTERVAL seconds ($(($REFRESH_INTERVAL / 3600)) hours)"
    log ""
    
    sleep $REFRESH_INTERVAL
done
