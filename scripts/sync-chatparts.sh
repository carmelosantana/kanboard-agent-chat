#!/usr/bin/env bash
# Vendors the chatparts UI kit into vendor-chatparts/ (hash-checked by chatparts' own sync-into.sh).
# Usage: scripts/sync-chatparts.sh <chatparts-dir>
set -euo pipefail
unset CDPATH

src="${1:-}"
[[ -n "$src" ]] || { echo "usage: scripts/sync-chatparts.sh <chatparts-dir>" >&2; exit 1; }
[[ -x "$src/scripts/sync-into.sh" ]] || { echo "sync-chatparts: no scripts/sync-into.sh under $src" >&2; exit 1; }

plugin="$(cd -P -- "$(dirname -- "$0")/.." && pwd -P)"
exec "$src/scripts/sync-into.sh" "$plugin"
