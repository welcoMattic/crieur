#!/bin/sh
# Builds build/defuddle, the article extractor of Crieur, from the pinned packages (bun.lock).
# Needs Bun 1.3.2 (later versions up to 1.3.11 work too; 1.3.12 and later produce broken binaries).
#   tools/defuddle/build.sh            for this machine
#   TARGET=bun-linux-x64 tools/defuddle/build.sh   for another platform
set -eu
cd "$(dirname "$0")"
BUN="${BUN:-$(command -v bun || echo "$HOME/.bun/bin/bun")}"
[ -x "$BUN" ] || { echo "build.sh: Bun is missing: curl -fsSL https://bun.sh/install | bash -s bun-v1.3.2" >&2; exit 1; }
"$BUN" install --frozen-lockfile 2>/dev/null || "$BUN" install
mkdir -p build
"$BUN" build --compile ${TARGET:+--target="$TARGET"} extract.mjs --outfile build/defuddle
if [ -z "${TARGET:-}" ] && [ "$(uname)" = "Darwin" ]; then
    codesign --force -s - build/defuddle 2>/dev/null || true
fi
if [ -z "${TARGET:-}" ]; then
    echo "build.sh: build/defuddle $(build/defuddle --version)"
fi
