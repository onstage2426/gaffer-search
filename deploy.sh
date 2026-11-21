#!/usr/bin/env bash
# Build + rsync this source checkout straight to a deployed plugin directory,
# using the same exclude list as the tagged-release ZIP (.deployignore) —
# so a change can be tried on a real site without cutting a release.
#
# Usage:
#   GAFFER_SEARCH_DEPLOY_TARGET=/path/to/wp-content/plugins/gaffer-search bun run deploy
#   bun run deploy -- /path/to/wp-content/plugins/gaffer-search

set -euo pipefail

target="${1:-${GAFFER_SEARCH_DEPLOY_TARGET:-}}"
if [[ -z "$target" ]]; then
    echo "error: no deploy target given. Set GAFFER_SEARCH_DEPLOY_TARGET or pass a path argument." >&2
    exit 1
fi

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

(cd "$root_dir" && bun run build)

# The prefixed updater library the plugin file requires: release builds generate it
# (.github/workflows/release.yml); a source checkout doesn't have it until this runs.
if [[ ! -d "$root_dir/src/WpHubUpdater" ]]; then
    (cd "$root_dir" && vendor/bin/wp-hub-updater GafferSearch src/WpHubUpdater)
fi

mkdir -p "$target"
rsync -a --delete --exclude-from="$root_dir/.deployignore" "$root_dir/" "$target/"

# Stamp a version newer than the last release (v0.2.1 + 7 commits -> 0.2.1.7), so the plugin's
# auto-updater doesn't replace this deploy with that release. A newer release still replaces it.
described="$(git -C "$root_dir" describe --tags --match 'v*' 2>/dev/null || true)"
if [[ "$described" =~ ^v([0-9.]+)(-([0-9]+)-g[0-9a-f]+)?$ ]]; then
    version="${BASH_REMATCH[1]}${BASH_REMATCH[3]:+.${BASH_REMATCH[3]}}"
    sed -i "s/^ \* Version:.*/ * Version: ${version}/" "$target/gaffer-search.php"
fi

echo "Deployed to $target${version:+ (version $version)}"
