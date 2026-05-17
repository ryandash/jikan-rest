#!/usr/bin/env bash

set -euo pipefail

generate_secret() {
    openssl rand -base64 48 | tr -d '\n'
}

generate_username() {
    printf "admin_%s" "$(openssl rand -hex 4)"
}

write_if_missing() {
    local filename="$1"
    local value="$2"

    if [ ! -f "$filename" ]; then
        printf "%s" "$value" > "$filename"
        echo "Created $filename"
    else
        echo "Skipped $filename (already exists)"
    fi
}

echo "Generating secrets in project root..."

# Passwords / API keys
write_if_missing "db_admin_password.txt" "$(generate_secret)"
write_if_missing "db_password.txt" "$(generate_secret)"
write_if_missing "redis_password.txt" "$(generate_secret)"
write_if_missing "typesense_api_key.txt" "$(generate_secret)"

# Usernames
write_if_missing "db_admin_username.txt" "$(generate_username)"

# Fixed username
write_if_missing "db_username.txt" "jikan"

echo "Done."