#!/bin/bash
set -e

# Version: YY.WW.PATCH from the ISO week (scripts/bump-version.sh), unless
# one is given as the first argument.
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  VERSION="$(scripts/bump-version.sh)"
fi

if [ -z "$VERSION" ] || [ "$VERSION" = "null" ]; then
  echo "ERROR: Could not determine the version"
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

# Sync version across all config files (package.json, tauri.conf.json, Cargo.toml x2, Cargo.lock)
scripts/bump-version.sh --set "$VERSION" >/dev/null
echo "Synced version ${VERSION}"

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
