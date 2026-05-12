#!/bin/bash
set -e

VERSION="${1:-0.1.0}"

echo "Building MEtR v${VERSION}..."

# Ensure signing key is available
if [ -z "$TAURI_SIGNING_PRIVATE_KEY" ] && [ -f "$HOME/.tauri/metr.key" ]; then
  export TAURI_SIGNING_PRIVATE_KEY="$(cat "$HOME/.tauri/metr.key")"
  export TAURI_SIGNING_PRIVATE_KEY_PASSWORD=""
  echo "Using signing key from $HOME/.tauri/metr.key"
fi

if [ -z "$TAURI_SIGNING_PRIVATE_KEY" ]; then
  echo "ERROR: No signing key found. Set TAURI_SIGNING_PRIVATE_KEY or place key at ~/.tauri/metr.key"
  exit 1
fi

# Update version in files
# (For now, manual version bumping is expected in package.json, Cargo.toml, tauri.conf.json)

npm run tauri:build

echo ""
echo "Build complete. Artifacts:"
find src-tauri/target/release/bundle -type f \( -name "*.dmg" -o -name "*.msi" -o -name "*.tar.gz" -o -name "*.sig" -o -name "*.exe" \) | while read f; do
  echo "  $f"
done

echo ""
echo "To publish this release to the server, run:"
echo "  php artisan metr:release:publish --version=${VERSION} \\"
echo "    --notes='Release notes here' \\"
echo "    --darwin-dmg=src-tauri/target/release/bundle/dmg/MEtR_${VERSION}_aarch64.dmg \\"
echo "    --darwin-sig=src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig"
