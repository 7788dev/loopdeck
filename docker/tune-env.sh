#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
project_dir=$(CDPATH= cd -- "$script_dir/.." && pwd)
cd "$project_dir"

env_file=${1:-.env}

if [ ! -f "$env_file" ]; then
    cp .env.example "$env_file"
fi

cpu_count=${TUNE_CPU_COUNT:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null || echo 1)}
if [ -n "${TUNE_MEMORY_MB:-}" ]; then
    memory_mb=$TUNE_MEMORY_MB
else
    memory_kb=$(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo)
    memory_mb=$((memory_kb / 1024))
fi

case "$cpu_count:$memory_mb" in
    *[!0-9:]*|:*|*:) echo "Unable to detect valid CPU or memory values" >&2; exit 1 ;;
esac

if [ "$memory_mb" -lt 900 ]; then
    echo "LoopDeck requires at least 900 MB RAM; detected ${memory_mb} MB" >&2
    exit 1
fi

if [ "$memory_mb" -lt 1536 ]; then
    reserve_mb=256
elif [ "$memory_mb" -lt 4096 ]; then
    reserve_mb=448
else
    reserve_mb=$((memory_mb * 15 / 100))
    if [ "$reserve_mb" -lt 512 ]; then
        reserve_mb=512
    fi
fi

usable_mb=$((memory_mb - reserve_mb - 32))
db_memory_mb=$((usable_mb * 55 / 100))
app_memory_mb=$((usable_mb - db_memory_mb))

if [ "$db_memory_mb" -lt 320 ]; then
    db_memory_mb=320
fi
if [ "$app_memory_mb" -lt 256 ]; then
    app_memory_mb=256
fi

db_swap_mb=$((db_memory_mb + 256))
app_swap_mb=$((app_memory_mb + 128))
buffer_pool_mb=$((db_memory_mb * 60 / 100))
if [ "$buffer_pool_mb" -lt 128 ]; then
    buffer_pool_mb=128
fi

worker_by_cpu=$((cpu_count * 2))
worker_by_memory=$((app_memory_mb / 64))
php_workers=$worker_by_cpu
if [ "$worker_by_memory" -lt "$php_workers" ]; then
    php_workers=$worker_by_memory
fi
if [ "$php_workers" -lt 2 ]; then
    php_workers=2
elif [ "$php_workers" -gt 64 ]; then
    php_workers=64
fi

php_memory_mb=$((app_memory_mb / (php_workers + 1)))
if [ "$php_memory_mb" -lt 128 ]; then
    php_memory_mb=128
elif [ "$php_memory_mb" -gt 256 ]; then
    php_memory_mb=256
fi

scheduler_workers=$cpu_count
if [ "$scheduler_workers" -lt 1 ]; then
    scheduler_workers=1
elif [ "$scheduler_workers" -gt 8 ]; then
    scheduler_workers=8
fi

total_scheduler_batch=$((cpu_count * 50))
if [ "$total_scheduler_batch" -lt 50 ]; then
    total_scheduler_batch=50
elif [ "$total_scheduler_batch" -gt 1000 ]; then
    total_scheduler_batch=1000
fi
scheduler_batch=$(((total_scheduler_batch + scheduler_workers - 1) / scheduler_workers))

mysql_connections=$((cpu_count * 25))
if [ "$mysql_connections" -lt 30 ]; then
    mysql_connections=30
elif [ "$mysql_connections" -gt 400 ]; then
    mysql_connections=400
fi

tmp_table_mb=$((db_memory_mb / 16))
if [ "$tmp_table_mb" -lt 16 ]; then
    tmp_table_mb=16
elif [ "$tmp_table_mb" -gt 64 ]; then
    tmp_table_mb=64
fi

app_cpu=$(awk -v c="$cpu_count" 'BEGIN { v=c*0.85; if (v<0.75) v=0.75; printf "%.2f", v }')
db_cpu=$(awk -v c="$cpu_count" 'BEGIN { v=c*0.75; if (v<0.75) v=0.75; printf "%.2f", v }')

set_env() {
    key=$1
    value=$2
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
}

set_env APP_MEMORY_LIMIT "${app_memory_mb}m"
set_env APP_MEMORY_SWAP_LIMIT "${app_swap_mb}m"
set_env APP_CPU_LIMIT "$app_cpu"
set_env DB_MEMORY_LIMIT "${db_memory_mb}m"
set_env DB_MEMORY_SWAP_LIMIT "${db_swap_mb}m"
set_env DB_CPU_LIMIT "$db_cpu"
set_env PHP_FPM_PM_MAX_CHILDREN "$php_workers"
set_env PHP_MEMORY_LIMIT "${php_memory_mb}M"
set_env SCHEDULER_BATCH_SIZE "$scheduler_batch"
set_env SCHEDULER_WORKERS "$scheduler_workers"
set_env MYSQL_MAX_CONNECTIONS "$mysql_connections"
set_env MYSQL_INNODB_BUFFER_POOL_SIZE "${buffer_pool_mb}M"
set_env MYSQL_TMP_TABLE_SIZE "${tmp_table_mb}M"

chmod 600 "$env_file"

echo "Auto-tuned LoopDeck for ${cpu_count} CPU(s), ${memory_mb} MB RAM"
echo "  app=${app_memory_mb} MB, db=${db_memory_mb} MB, php=${php_workers}x${php_memory_mb} MB, scheduler=${scheduler_workers}x${scheduler_batch}"
