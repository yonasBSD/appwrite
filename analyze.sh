#!/usr/bin/env bash

set -euo pipefail

ROOT="/Users/matejbaco/Documents/GitHub/appwrite"
ENDPOINT_DIR="$ROOT/src/Appwrite/Platform/Modules/Project/Http/Project/OAuth2"
CONFIG_FILE="$ROOT/app/config/oAuthProviders.php"

if ! command -v php >/dev/null 2>&1; then
    echo "php is required but was not found in PATH" >&2
    exit 1
fi

if [[ ! -d "$ENDPOINT_DIR" ]]; then
    echo "Endpoint directory not found: $ENDPOINT_DIR" >&2
    exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Config file not found: $CONFIG_FILE" >&2
    exit 1
fi

echo "OAuth2 endpoint files:"
find "$ENDPOINT_DIR" -type f | sort
echo

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

endpoint_file="$tmp_dir/endpoint-providers.txt"
config_file="$tmp_dir/config-providers.txt"

find "$ENDPOINT_DIR" -mindepth 2 -maxdepth 2 -type f -name 'Update.php' \
    | while read -r file; do
        basename "$(dirname "$file")" | tr '[:upper:]' '[:lower:]'
    done \
    | sort -u > "$endpoint_file"

php -r '
    $providers = require $argv[1];
    $names = [];

    foreach ($providers as $provider) {
        if (($provider["mock"] ?? false) === true) {
            continue;
        }

        $class = $provider["class"] ?? "";
        if ($class === "") {
            continue;
        }

        $base = substr($class, strrpos($class, "\\") + 1);
        $names[strtolower($base)] = true;
    }

    $names = array_keys($names);
    sort($names);

    foreach ($names as $name) {
        echo $name, PHP_EOL;
    }
' "$CONFIG_FILE" > "$config_file"

echo "Configured provider classes:"
cat "$config_file"
echo

echo "Endpoint provider directories:"
cat "$endpoint_file"
echo

echo "Configured providers without endpoint:"
comm -23 "$config_file" "$endpoint_file"
