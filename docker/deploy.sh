#!/bin/sh
set -eu
umask 077

script_dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
project_dir=$(CDPATH= cd -- "$script_dir/.." && pwd)
cd "$project_dir"

prepare_only=false
if [ "${1:-}" = --prepare-only ]; then
    prepare_only=true
    shift
fi
if [ "$#" -gt 1 ]; then
    echo "用法：sh docker/deploy.sh [--prepare-only] [配置文件路径]" >&2
    exit 1
fi
env_file=${1:-.env}

if ! command -v docker >/dev/null 2>&1; then
    echo "请先安装 Docker Engine 和 Docker Compose 2.20.0 或更新版本" >&2
    exit 1
fi
compose_version=$(docker compose version --short) || exit 1
if ! printf '%s\n' "$compose_version" | awk -F. '
    { sub(/^v/, "", $1); exit !(($1 + 0) > 2 || (($1 + 0) == 2 && ($2 + 0) >= 20)) }
'; then
    echo "需要 Docker Compose 2.20.0 或更新版本，当前版本为 $compose_version" >&2
    exit 1
fi
docker info >/dev/null

if [ ! -f "$env_file" ]; then
    cp .env.example "$env_file"
fi

random_hex() {
    bytes=$1
    od -An -N "$bytes" -tx1 /dev/urandom | tr -d ' \n'
}

read_env() {
    awk -v key="$1" '
        $0 ~ "^[ \t]*(export[ \t]+)?" key "[ \t]*=" {
            sub(/^[^=]*=[ \t]*/, "")
            sub(/\r$/, "")
            sub(/[ \t]+$/, "")
            value = $0
            quote = substr(value, 1, 1)
            if (quote == "\042" || quote == "\047") {
                value = substr(value, 2)
                end = index(value, quote)
                if (end) value = substr(value, 1, end - 1)
            } else {
                sub(/[ \t]+#.*/, "", value)
            }
        }
        END { printf "%s", value }
    ' "$env_file"
}

set_env() {
    temporary=$(mktemp "${env_file}.XXXXXX")
    awk -v key="$1" -v value="$2" '
        BEGIN { replaced = 0 }
        $0 ~ "^[ \t]*(export[ \t]+)?" key "[ \t]*=" {
            if (!replaced) print key "=" value
            replaced = 1
            next
        }
        { print }
        END { if (!replaced) print key "=" value }
    ' "$env_file" > "$temporary"
    mv "$temporary" "$env_file"
}

set_if_placeholder() {
    current=$(read_env "$1")
    if [ -z "$current" ] || [ "$current" = "$2" ]; then
        set_env "$1" "$3"
    fi
}

# Database credentials must come from the saved file, including on subsequent
# deployments. Inherited shell variables must not silently point at another DB.
unset MYSQL_HOST MYSQL_PORT MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD MYSQL_ROOT_PASSWORD CRON_KEY COMPOSE_PROFILES

set_if_placeholder MYSQL_HOST '' db
set_if_placeholder MYSQL_PORT '' 3306
database_host=$(read_env MYSQL_HOST)
database_port=$(read_env MYSQL_PORT)
case "$database_port" in
    ''|*[!0-9]*) echo "MYSQL_PORT 必须是 1–65535 之间的端口号" >&2; exit 1 ;;
esac
if [ "${#database_port}" -gt 5 ] || [ "$database_port" -lt 1 ] || [ "$database_port" -gt 65535 ]; then
    echo "MYSQL_PORT 必须是 1–65535 之间的端口号" >&2
    exit 1
fi

if [ "$database_host" = db ]; then
    if [ "$database_port" != 3306 ]; then
        echo "内置 MySQL 的 MYSQL_PORT 必须为 3306" >&2
        exit 1
    fi
    needs_credentials=false
    for credential_key in MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD MYSQL_ROOT_PASSWORD; do
        current=$(read_env "$credential_key")
        case "$current" in
            ''|replace_with_a_random_password|replace_with_another_random_password) needs_credentials=true ;;
        esac
    done
    if [ "$needs_credentials" = true ]; then
        project_name=${COMPOSE_PROJECT_NAME:-$(read_env COMPOSE_PROJECT_NAME)}
        project_name=${project_name:-loopdeck}
        existing_volume=$(docker volume ls --quiet \
            --filter "label=com.docker.compose.project=$project_name" \
            --filter 'label=com.docker.compose.volume=db_data')
        if [ -n "$existing_volume" ]; then
            echo "检测到已有 MySQL 数据卷，但数据库配置缺失。请恢复原 .env；不会生成新凭据或重置数据。" >&2
            exit 1
        fi
    fi
    set_if_placeholder MYSQL_DATABASE '' "loopdeck_$(random_hex 6)"
    set_if_placeholder MYSQL_USER '' "ld_$(random_hex 8)"
    set_if_placeholder MYSQL_PASSWORD replace_with_a_random_password "$(random_hex 24)"
    set_if_placeholder MYSQL_ROOT_PASSWORD replace_with_another_random_password "$(random_hex 32)"
    echo "使用内置 MySQL，数据库配置已保存，后续部署将继续复用"
else
    for required_key in MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD; do
        current=$(read_env "$required_key")
        case "$current" in
            ''|replace_with_a_random_password)
                echo "连接外部 MySQL 时，请在配置文件中填写 $required_key" >&2
                exit 1
                ;;
        esac
    done
    echo "使用自定义 MySQL 连接，本次不启动内置数据库"
fi

# Keep any user-defined profiles while selecting the bundled database.
profiles=$(read_env COMPOSE_PROFILES | tr ',' '\n' | awk '
    { gsub(/^[ \t]+|[ \t]+$/, "") }
    $0 != "" && $0 != "local-db" { printf "%s%s", separator, $0; separator = "," }
')
if [ "$database_host" = db ]; then
    profiles="local-db${profiles:+,$profiles}"
fi
set_env COMPOSE_PROFILES "$profiles"

set_if_placeholder CRON_KEY replace_with_a_long_random_cron_key "$(random_hex 48)"

sh "$script_dir/tune-env.sh" "$env_file"

docker compose --env-file "$env_file" config --quiet
if [ "$prepare_only" = true ]; then
    echo "配置已生成并通过 Compose 校验，尚未启动容器"
    exit 0
fi

docker compose --env-file "$env_file" pull
docker compose --env-file "$env_file" up --no-build --wait --wait-timeout 180
