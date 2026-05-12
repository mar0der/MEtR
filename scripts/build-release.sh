#!/bin/bash
set -e

# Read version from tauri.conf.json as the source of truth
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  if command -v jq >/dev/null 2>&1; then
    VERSION="$(jq -r '.version' src-tauri/tauri.conf.json)"
  else
    VERSION="$(grep '"version"' src-tauri/tauri.conf.json | head -1 | sed -E 's/.*"version": *"([^"]+)".*/\1/')"
  fi
fi

if [ -z "$VERSION" ] || [ "$VERSION" = "null" ]; then
  echo "ERROR: Could not determine version from src-tauri/tauri.conf.json"
  exit 1
fi

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

# Sync version across all config files
sed -i.bak -E "s/\"version\": *\"[^\"]+\"/\"version\": \"${VERSION}\"/" package.json && rm -f package.json.bak
sed -i.bak -E "s/^version = \"[^\"]+\"/version = \"${VERSION}\"/" src-tauri/Cargo.toml && rm -f src-tauri/Cargo.toml.bak
echo "Synced version ${VERSION} to package.json and Cargo.toml"

npm run tauri:build

echo ""
echo "Build complete. Artifacts:"
find src-tauri/target/release/bundle -type f \( -name "*.dmg" -o -name "*.msi" -o -name "*.tar.gz" -o -name "*.sig" -o -name "*.exe" \) | while read f; do
  echo "  $f"
done

echo ""
echo "To publish this release to the server, run:"
echo "  php artisan metr:release:publish --release-version=${VERSION} \\"
echo "    --notes='Release notes here' \\"
echo "    --darwin-tgz=src-tauri/target/release/bundle/macos/MEtR.app.tar.gz \\"
echo "    --darwin-sig=src-tauri/target/release/bundle/macos/MEtR.app.tar.gz.sig \\"
echo "    --darwin-dmg=src-tauri/target/release/bundle/dmg/MEtR_${VERSION}_aarch64.dmg"
