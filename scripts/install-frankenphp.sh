#!/bin/sh
set -eu

REPO_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$REPO_ROOT"

BINARY="$REPO_ROOT/frankenphp"
VERSION=$(cat "$REPO_ROOT/scripts/frankenphp/VERSION")
EXPECTED_SHA256=$(cat "$REPO_ROOT/scripts/frankenphp/SHA256")

current_version() {
    [ -x "$BINARY" ] || return 1
    "$BINARY" version 2>/dev/null | sed -n 's/^FrankenPHP v\([0-9][0-9.]*\).*/\1/p'
}

if [ "$(current_version || true)" = "$VERSION" ]; then
    echo "FrankenPHP v${VERSION} is installed."
    exit 0
fi

if ! getconf GNU_LIBC_VERSION >/dev/null 2>&1; then
    echo "The pinned FrankenPHP build requires glibc; install v${VERSION} manually for this libc." >&2
    exit 1
fi

case "$(uname -m)" in
    x86_64) ASSET=frankenphp-linux-x86_64-gnu ;;
    aarch64|arm64) ASSET=frankenphp-linux-aarch64-gnu ;;
    *)
        echo "Unsupported architecture: $(uname -m)" >&2
        exit 1
        ;;
esac

if [ "$ASSET" != "frankenphp-linux-x86_64-gnu" ]; then
    echo "No pinned checksum for ${ASSET}; update scripts/frankenphp/SHA256 before installing." >&2
    exit 1
fi

DOWNLOAD_DIR="$REPO_ROOT/storage/app/frankenphp"
mkdir -p "$DOWNLOAD_DIR"
TMP=$(mktemp "$DOWNLOAD_DIR/frankenphp.XXXXXX")
trap 'rm -f "$TMP"' EXIT INT TERM

echo "==> Downloading FrankenPHP v${VERSION} (${ASSET})"
curl -fL --retry 3 --connect-timeout 15 \
    "https://github.com/php/frankenphp/releases/download/v${VERSION}/${ASSET}" \
    -o "$TMP"

ACTUAL_SHA256=$(sha256sum "$TMP" | awk '{print $1}')
if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
    echo "Checksum mismatch for FrankenPHP v${VERSION}." >&2
    echo "expected: ${EXPECTED_SHA256}" >&2
    echo "actual:   ${ACTUAL_SHA256}" >&2
    exit 1
fi

chmod 0755 "$TMP"
mv "$TMP" "$BINARY"
trap - EXIT INT TERM

"$BINARY" version
