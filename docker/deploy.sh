#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
project_dir=$(CDPATH= cd -- "$script_dir/.." && pwd)
cd "$project_dir"

env_file=${1:-.env}

if [ ! -f "$env_file" ]; then
    cp .env.example "$env_file"
fi

random_hex() {
    bytes=$1
    od -An -N "$bytes" -tx1 /dev/urandom | tr -d ' \n'
}

set_if_placeholder() {
    key=$1
    placeholder=$2
    value=$3
    current=$(awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "$env_file")
    if [ -z "$current" ] || [ "$current" = "$placeholder" ]; then
        temporary=$(mktemp "${env_file}.XXXXXX")
        awk -v key="$key" -v value="$value" '
            BEGIN { replaced = 0 }
            index($0, key "=") == 1 {
                if (!replaced) print key "=" value
                replaced = 1
                next
            }
            { print }
            END { if (!replaced) print key "=" value }
        ' "$env_file" > "$temporary"
        mv "$temporary" "$env_file"
    fi
}

set_if_placeholder MYSQL_PASSWORD replace_with_a_random_password "$(random_hex 24)"
set_if_placeholder MYSQL_ROOT_PASSWORD replace_with_another_random_password "$(random_hex 32)"
set_if_placeholder CRON_KEY replace_with_a_long_random_cron_key "$(random_hex 48)"
set_if_placeholder UPDATE_TOKEN replace_with_a_long_random_update_token "$(random_hex 48)"

"$script_dir/tune-env.sh" "$env_file"

docker compose --env-file "$env_file" pull
docker compose --env-file "$env_file" up --no-build --wait --wait-timeout 180
