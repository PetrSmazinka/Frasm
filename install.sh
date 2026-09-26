#!/usr/bin/env bash
# =============================================================================
# Frasm installer / updater
# -----------------------------------------------------------------------------
# Downloads the framework and creates a new project (or updates an existing one).
#
#   New project:
#     wget -qO install.sh https://raw.githubusercontent.com/PetrSmazinka/Frasm/master/install.sh
#     bash install.sh /var/www/myapp
#
#   Update an existing project (from inside it):
#     ./install.sh --update .            # or: make update
#
# Options:
#   --ref <branch|tag>   Version to install (default: master; prefer a tag in production)
#   --update             Replace framework files only (app/, storage/, config are kept)
#   --migrate            With --update: also run database migrations
#   --no-example         Do not install the Hello world application
#   --web-user <user>    Web server user owning storage/ (default: www-data)
#   --source <dir>       Use a local framework checkout instead of downloading (development)
#
# Private repository access:
#   FRASM_REPO=git@github.com:PetrSmazinka/Frasm.git  → git clone over SSH (deploy key)
#   GITHUB_TOKEN=<token>                               → tarball via the GitHub API (no git needed)
# =============================================================================
set -euo pipefail

REPO="${FRASM_REPO:-https://github.com/PetrSmazinka/Frasm.git}"
REF="master"
TARGET=""
SOURCE=""
PASS_ARGS=()

usage() {
    sed -n '2,25p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

die() {
    echo "✖ $*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ref)        REF="${2:?--ref requires a value}"; shift 2 ;;
        --source)     SOURCE="${2:?--source requires a value}"; shift 2 ;;
        --web-user)   PASS_ARGS+=("--web-user=${2:?--web-user requires a value}"); shift 2 ;;
        --update)     PASS_ARGS+=("--update"); shift ;;
        --migrate)    PASS_ARGS+=("--migrate"); shift ;;
        --no-example) PASS_ARGS+=("--no-example"); shift ;;
        -h|--help)    usage 0 ;;
        -*)           die "Unknown option: $1" ;;
        *)            [[ -z "$TARGET" ]] || die "Only one target directory may be given."; TARGET="$1"; shift ;;
    esac
done

[[ -n "$TARGET" ]] || usage 1
[[ "$REF" =~ ^[A-Za-z0-9._/-]+$ ]] || die "Invalid --ref '$REF'."

command -v php >/dev/null 2>&1 || die "PHP CLI is not installed."
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "PHP 8.3 or newer is required (found $(php -r 'echo PHP_VERSION;'))."

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
COMMIT=""

if [[ -n "$SOURCE" ]]; then
    [[ -f "$SOURCE/bin/install.php" ]] || die "'$SOURCE' is not a Frasm checkout."
    SRC="$(cd "$SOURCE" && pwd)"
    COMMIT="$(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || true)"
elif [[ -z "${GITHUB_TOKEN:-}" ]] && command -v git >/dev/null 2>&1; then
    echo "Fetching Frasm ($REF) from $REPO ..."
    git clone --quiet --depth 1 --branch "$REF" "$REPO" "$WORK/frasm" || die "git clone failed (private repository? set FRASM_REPO to an SSH URL or GITHUB_TOKEN)."
    SRC="$WORK/frasm"
    COMMIT="$(git -C "$SRC" rev-parse --short HEAD)"
else
    # Tarball download: https://github.com/<owner>/<repo>(.git) → API tarball endpoint
    SLUG="$(echo "$REPO" | sed -E 's#^(https://github.com/|git@github.com:)##; s#\.git$##')"
    URL="https://api.github.com/repos/${SLUG}/tarball/${REF}"
    AUTH=()
    [[ -n "${GITHUB_TOKEN:-}" ]] && AUTH=(-H "Authorization: Bearer ${GITHUB_TOKEN}")

    echo "Downloading Frasm ($REF) from $URL ..."
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "${AUTH[@]}" -o "$WORK/frasm.tar.gz" "$URL" || die "Download failed."
    elif command -v wget >/dev/null 2>&1; then
        WGET_AUTH=()
        [[ -n "${GITHUB_TOKEN:-}" ]] && WGET_AUTH=(--header "Authorization: Bearer ${GITHUB_TOKEN}")
        wget -q "${WGET_AUTH[@]}" -O "$WORK/frasm.tar.gz" "$URL" || die "Download failed."
    else
        die "Neither git, curl nor wget is available."
    fi

    mkdir "$WORK/frasm"
    tar -xzf "$WORK/frasm.tar.gz" -C "$WORK/frasm" --strip-components=1
    SRC="$WORK/frasm"
    # GitHub tarballs are named <owner>-<repo>-<sha>
    COMMIT="$(tar -tzf "$WORK/frasm.tar.gz" | head -1 | sed -E 's#.*-([0-9a-f]{7,})/?$#\1#')"
fi

php "$SRC/bin/install.php" --target="$TARGET" --ref="$REF" --commit="$COMMIT" "${PASS_ARGS[@]}"
