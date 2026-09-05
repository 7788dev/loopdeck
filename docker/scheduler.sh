#!/bin/sh
set -eu

cron_key=${CRON_KEY:-}
scheduler_url=${SCHEDULER_URL:-http://app:8080/cron/task}
notification_url=${NOTIFICATION_URL:-http://app:8080/cron/notifications}
interval=${SCHEDULER_INTERVAL_SECONDS:-60}
workers=${SCHEDULER_WORKERS:-1}

if [ -z "$cron_key" ]; then
    echo "CRON_KEY is required" >&2
    exit 1
fi

case "$interval" in
    ''|*[!0-9]*) interval=60 ;;
esac
if [ "$interval" -lt 10 ]; then
    interval=10
fi

case "$workers" in
    ''|*[!0-9]*) workers=1 ;;
esac
if [ "$workers" -lt 1 ]; then
    workers=1
elif [ "$workers" -gt 16 ]; then
    workers=16
fi

run_worker() {
    worker=$1
    separator='?'
    case "$scheduler_url" in
        *\?*) separator='&' ;;
    esac
    url="${scheduler_url}${separator}worker=${worker}&workers=${workers}"

    if ! curl \
        --fail \
        --silent \
        --show-error \
        --connect-timeout 5 \
        --header "X-Cron-Key: $cron_key" \
        --output /dev/null \
        "$url"; then
        echo "$(date '+%Y-%m-%dT%H:%M:%S%z') scheduler worker ${worker}/${workers} failed" >&2
    fi
}

# Delivery has its own loop: a slow SMTP/push provider must not delay the
# next platform-task batch or make that task execute again.
run_notifications() {
    while true; do
        if ! curl --fail --silent --show-error --connect-timeout 5 --max-time 120 \
            --header "X-Cron-Key: $cron_key" --output /dev/null "$notification_url"; then
            echo "$(date '+%Y-%m-%dT%H:%M:%S%z') notification worker failed" >&2
        fi
        now=$(date +%s)
        sleep "$((interval - now % interval))"
    done
}

run_notifications &
notification_pid=$!
trap 'kill "$notification_pid" 2>/dev/null || true; exit 0' INT TERM

while true; do
    worker=0
    pids=''
    while [ "$worker" -lt "$workers" ]; do
        run_worker "$worker" &
        pids="$pids $!"
        worker=$((worker + 1))
    done
    for pid in $pids; do
        wait "$pid" || true
    done

    now=$(date +%s)
    delay=$((interval - now % interval))
    sleep "$delay"
done
