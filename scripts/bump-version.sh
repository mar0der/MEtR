#!/usr/bin/env bash
# Sets the METR version to YY.WW.PATCH: ISO year and ISO week of today (or
# of --date), patch = one more than the highest existing vYY.WW.* tag (0 if
# none). Weeks have no leading zero (26.5.0), because Cargo and Tauri need
# valid semver. Updates package.json, src-tauri/tauri.conf.json, both
# Cargo.toml files and Cargo.lock. Prints the version.
#   scripts/bump-version.sh            # this week
#   scripts/bump-version.sh --date 2026-10-29
#   scripts/bump-version.sh --check    # only verify files match each other
#   scripts/bump-version.sh --set 26.44.1   # a given version (still YY.WW.PATCH)
set -euo pipefail
cd "$(dirname "$0")/.."

files_version() {
  local pkg tauri cargo cli
  pkg=$(node -p "require('./package.json').version")
  tauri=$(node -p "require('./src-tauri/tauri.conf.json').version")
  cargo=$(sed -n 's/^version = "\([^"]*\)"/\1/p' src-tauri/Cargo.toml | head -1)
  cli=$(sed -n 's/^version = "\([^"]*\)"/\1/p' src-tauri/cli/Cargo.toml | head -1)
  if [ "$pkg" != "$tauri" ] || [ "$pkg" != "$cargo" ] || [ "$pkg" != "$cli" ]; then
    echo "version mismatch: package.json $pkg, tauri.conf.json $tauri, Cargo.toml $cargo, cli/Cargo.toml $cli" >&2
    return 1
  fi
  echo "$pkg"
}

if [ "${1:-}" = "--check" ]; then
  files_version
  exit 0
fi

if [ "${1:-}" = "--set" ]; then
  VERSION="${2:-}"
  if ! [[ "$VERSION" =~ ^[0-9]{2}\.[1-9][0-9]?\.[0-9]+$ ]]; then
    echo "version must be YY.WW.PATCH (ISO week, no leading zero), got '$VERSION'" >&2
    exit 1
  fi
else
  DATE_ARG=()
  if [ "${1:-}" = "--date" ]; then
    DATE_ARG=(-d "$2")
  fi
  YEAR=$(date "${DATE_ARG[@]}" +%G)
  WEEK=$(date "${DATE_ARG[@]}" +%V)
  PREFIX="$((10#${YEAR:2:2})).$((10#$WEEK))"

  git fetch -q --tags 2>/dev/null || true
  LAST=$(git tag -l "v$PREFIX.*" | sed "s/^v$PREFIX\.//" | { grep -E '^[0-9]+$' || true; } | sort -n | tail -1)
  PATCH=$(( ${LAST:-"-1"} + 1 ))
  VERSION="$PREFIX.$PATCH"
fi

# Only the first "version" key; leaves the rest of the JSON untouched.
for json in package.json src-tauri/tauri.conf.json; do
  sed -i -E "0,/\"version\": *\"[^\"]+\"/s//\"version\": \"$VERSION\"/" "$json"
done
for toml in src-tauri/Cargo.toml src-tauri/cli/Cargo.toml; do
  sed -i -E "0,/^version = \"[^\"]+\"/s//version = \"$VERSION\"/" "$toml"
done
# Cargo.lock entries of the two workspace packages.
sed -i -E "/^name = \"metr(-cli)?\"$/{n;s/^version = \"[^\"]+\"/version = \"$VERSION\"/}" src-tauri/Cargo.lock
files_version >/dev/null
echo "$VERSION"
