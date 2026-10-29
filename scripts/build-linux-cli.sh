#!/usr/bin/env bash
# Builds the portable headless `metr` CLI (x86_64 Linux, glibc >= 2.17, no
# OpenSSL) and packages it as src-tauri/target/dist/metr-<version>-linux-x86_64.tar.gz.
# Needs: cargo, cargo-zigbuild (`cargo install --locked cargo-zigbuild`) and
# zig 0.13 on PATH (e.g. ~/.local/opt/zig-linux-x86_64-0.13.0).
set -euo pipefail
cd "$(dirname "$0")/../src-tauri"

ZIG_DIR="${ZIG_DIR:-$HOME/.local/opt/zig-linux-x86_64-0.13.0}"
[ -d "$ZIG_DIR" ] && export PATH="$ZIG_DIR:$PATH"
command -v zig >/dev/null || { echo "zig not found (set ZIG_DIR)"; exit 1; }
command -v cargo-zigbuild >/dev/null || { echo "cargo-zigbuild not found"; exit 1; }

TARGET=x86_64-unknown-linux-gnu
nice -n 19 cargo zigbuild --release --locked -p metr-cli --target "$TARGET.2.17"
BIN="target/$TARGET/release/metr-cli"
VERSION="$("$BIN" --version | awk '{print $2}')"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
NAME="metr-$VERSION-linux-x86_64"
mkdir -p "$STAGE/$NAME" target/dist
install -m 0755 "$BIN" "$STAGE/$NAME/metr"
cp ../docs/linux-cli.md "$STAGE/$NAME/README.md"
tar -C "$STAGE" -czf "target/dist/$NAME.tar.gz" "$NAME"
(cd target/dist && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
echo "target/dist/$NAME.tar.gz"
cat "target/dist/$NAME.tar.gz.sha256"
