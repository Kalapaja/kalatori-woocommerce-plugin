#!/usr/bin/env bash
# Bundle the plugin into a ZIP archive suitable for WordPress "Add Plugin" upload.
# The ZIP contains a single `kalatori-payment-plugin/` folder as WP requires.

set -euo pipefail

DAEMON_URL=""
SECRET_KEY=""
ADMIN_URL=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --daemon_url) DAEMON_URL="$2"; shift 2 ;;
        --secret_key) SECRET_KEY="$2"; shift 2 ;;
        --admin_url)  ADMIN_URL="$2";  shift 2 ;;
        *) echo "Unknown parameter: $1" >&2; exit 1 ;;
    esac
done


PLUGIN_SLUG="kalatori-payment-plugin"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

VERSION=$(grep -m1 'Version:' "$ROOT_DIR/kalatori-payment-plugin.php" | awk '{print $NF}')
OUT_FILE="$ROOT_DIR/${PLUGIN_SLUG}.zip"
TMP_DIR=$(mktemp -d)

cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT

rsync -a \
    --include='kalatori-payment-plugin.php' \
    --include='assets/' \
    --include='assets/**' \
    --exclude='*' \
    "$ROOT_DIR/" "$TMP_DIR/$PLUGIN_SLUG/"

if [[ -n "$DAEMON_URL" || -n "$SECRET_KEY" || -n "$ADMIN_URL" ]]; then
    printf '{\n  "daemon_url": "%s",\n  "secret_key": "%s",\n  "admin_url": "%s"\n}\n' \
        "$DAEMON_URL" "$SECRET_KEY" "$ADMIN_URL" \
        > "$TMP_DIR/$PLUGIN_SLUG/woocommerce-kalatori-config.json"
fi

rm -f "$OUT_FILE"
cd "$TMP_DIR"
zip -r "$OUT_FILE" "$PLUGIN_SLUG/"

echo "Bundled: $OUT_FILE (version $VERSION)"
