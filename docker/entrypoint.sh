#!/bin/sh
set -eu

app_root=/var/www/html
data_root=/var/lib/loopdeck

mkdir -p \
    "$data_root/config" \
    "$data_root/runtime" \
    "$data_root/sessions" \
    "$data_root/uploads"

# Refresh versioned configuration while retaining the generated Db.php file.
cp -a /opt/loopdeck-config/. "$data_root/config/"

link_directory() {
    source_path="$1"
    target_path="$2"

    if [ -d "$source_path" ] && [ ! -L "$source_path" ]; then
        cp -a "$source_path/." "$target_path/"
    fi

    if [ ! -L "$source_path" ]; then
        rm -rf "$source_path"
        ln -s "$target_path" "$source_path"
    fi
}

link_directory "$app_root/config" "$data_root/config"
link_directory "$app_root/runtime" "$data_root/runtime"
link_directory "$app_root/public/static/uploads" "$data_root/uploads"

chown -R www-data:www-data "$data_root"

exec docker-php-entrypoint "$@"
