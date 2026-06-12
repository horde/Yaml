#!/bin/bash
# Refresh the .horde.yml corpus used by the perf suite.
#
# The corpus is a snapshot of .horde.yml files from across local
# Horde component checkouts, taken once and committed to the repo so
# the perf test runs anywhere.
#
# Usage: run from the repo root.
#
#     ./scripts/snapshot-horde-yml-corpus.sh

set -e

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CORPUS_DIR="$REPO_ROOT/test/fixtures/perf/horde-yml-corpus"
SOURCE_DIR="${HORDE_GIT_DIR:-$HOME/php/git/horde}"

if [ ! -d "$SOURCE_DIR" ]; then
  echo "Source directory $SOURCE_DIR not found." >&2
  echo "Set HORDE_GIT_DIR to the directory containing your Horde component checkouts." >&2
  exit 1
fi

mkdir -p "$CORPUS_DIR"

# Wipe and re-populate so removed components disappear from the corpus.
rm -f "$CORPUS_DIR"/*.horde.yml

count=0
for component in "$SOURCE_DIR"/*; do
  if [ -f "$component/.horde.yml" ]; then
    name="$(basename "$component")"
    cp "$component/.horde.yml" "$CORPUS_DIR/$name.horde.yml"
    count=$((count + 1))
  fi
done

echo "Copied $count .horde.yml files to $CORPUS_DIR"
