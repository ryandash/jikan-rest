#!/bin/bash
set -eo pipefail

while IFS= read -r env_key; do
  [[ "$env_key" == *__FILE ]] || continue

  secret_path="${!env_key}"
  secret_env="${env_key%__FILE}"
  if [[ ! "$secret_env" =~ ^[A-Za-z_][A-Za-z0-9_]*$ || ! -r "$secret_path" ]]; then
    echo "Unable to load secret configured by ${env_key}." >&2
    exit 1
  fi

  secret_value="$(tr -d '\r\n' < "$secret_path")"
  export "${secret_env}=${secret_value}"
done < <(compgen -e)

status=0
if [[ $# -eq 0 ]] ; then
  php /app/docker-entrypoint.php
  status=$?
else
  php /app/docker-entrypoint.php "$@"
  status=$?
fi

[[ $status -ne 0 ]] && exit $status

exec rr serve -c .rr.yaml
