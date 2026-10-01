#!/usr/bin/env bash
# =============================================================================
# Frasm installer and updater
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
#   PHP only in a Docker container (the project directory is mounted into it):
#     bash install.sh --docker apache_php --docker-workdir /var/www/myapp ~/www/myapp
#     (then `make` runs every command in the container, see frasm.mk; `make update` keeps the settings)
#
#   Restore a project cloned from its own repository (the framework is git-ignored):
#     git clone <your-project-repo> /var/www/myapp && cd /var/www/myapp
#     cp /path/to/backup/local.php config/local.php
#     wget -qO install.sh https://raw.githubusercontent.com/PetrSmazinka/Frasm/master/install.sh
#     bash install.sh --restore .
#
# Options:
#   --ref <branch|tag>   Version to install (default: master; prefer a tag in production;
#                        --restore defaults to the version recorded in .frasm-version)
#   --update             Replace framework files only (app/, storage/, config are kept)
#   --restore            Install the framework files into a cloned project (like --update)
#   --migrate            With --update/--restore: also run database migrations
#   --no-example         Do not install the Hello world application
#   --pwa                Make the site installable as an app (manifest + icons)
#   --scheduler          Enable #[Schedule] tasks (adds the cron entry)
#   --web-user <user>    Web server user owning storage/ (default: www-data)
#   --source <dir>       Use a local framework checkout instead of downloading (development)
#   --docker <container> Run PHP in this running container; downloading stays on the host
#   --docker-workdir <dir>  The target directory as mounted inside the container (required with --docker)
#   --docker-user <uid:gid> User running PHP in the container (default: yours, with the target's group)
#
# Private repository access:
#   FRASM_REPO=git@github.com:PetrSmazinka/Frasm.git  → git clone over SSH (deploy key)
#   GITHUB_TOKEN=<token>                               → tarball via the GitHub API (no git needed)
# =============================================================================
set -euo pipefail

REPO="${FRASM_REPO:-https://github.com/PetrSmazinka/Frasm.git}"
REF=""
RESTORE=""
TARGET=""
SOURCE=""
DOCKER=""
DOCKER_WORKDIR=""
DOCKER_USER=""
PASS_ARGS=()

usage() {
    # The header comment, up to its closing ==== line
    awk 'NR > 2 && /^# =+$/ { exit } NR > 2' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

die() {
    echo "✖ $*" >&2
    exit 1
}

step() {
    echo "› $*"
}

# PHP CLI on the host, or in the container given by --docker (working directory: the target there)
php_cli() {
    if [[ -n "$DOCKER" ]]; then
        docker exec -u "$DOCKER_USER" -w "$DOCKER_WORKDIR" "$DOCKER" php "$@"
    else
        php "$@"
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ref)        REF="${2:?--ref requires a value}"; shift 2 ;;
        --source)     SOURCE="${2:?--source requires a value}"; shift 2 ;;
        --docker)     DOCKER="${2:?--docker requires a value}"; shift 2 ;;
        --docker-workdir) DOCKER_WORKDIR="${2:?--docker-workdir requires a value}"; shift 2 ;;
        --docker-user)    DOCKER_USER="${2:?--docker-user requires a value}"; shift 2 ;;
        --web-user)   PASS_ARGS+=("--web-user=${2:?--web-user requires a value}"); shift 2 ;;
        --update)     PASS_ARGS+=("--update"); shift ;;
        --restore)    PASS_ARGS+=("--restore"); RESTORE=1; shift ;;
        --migrate)    PASS_ARGS+=("--migrate"); shift ;;
        --no-example) PASS_ARGS+=("--no-example"); shift ;;
        --pwa)        PASS_ARGS+=("--pwa"); shift ;;
        --scheduler)  PASS_ARGS+=("--scheduler"); shift ;;
        -h|--help)    usage 0 ;;
        -*)           die "Unknown option: $1" ;;
        *)            [[ -z "$TARGET" ]] || die "Only one target directory may be given."; TARGET="$1"; shift ;;
    esac
done

[[ -n "$TARGET" ]] || usage 1

# A restore reinstalls the version the project was last installed or updated with
if [[ -z "$REF" && -n "$RESTORE" && -f "$TARGET/.frasm-version" ]]; then
    REF="$(sed -nE 's/^[[:space:]]*"ref":[[:space:]]*"([^"]+)".*/\1/p' "$TARGET/.frasm-version" | head -1)"
    [[ -n "$REF" ]] && step "Using version $REF from .frasm-version"
fi
REF="${REF:-master}"
[[ "$REF" =~ ^[A-Za-z0-9._/-]+$ ]] || die "Invalid --ref '$REF'."

if [[ -n "$DOCKER" ]]; then
    [[ "$DOCKER" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || die "Invalid --docker '$DOCKER'."
    [[ "$DOCKER_WORKDIR" == /* ]] || die "--docker requires --docker-workdir with an absolute path inside the container."
    command -v docker >/dev/null 2>&1 || die "Docker is not installed."
    # The container sees the target through a mount, so the target must exist on the host first
    mkdir -p "$TARGET" || die "Cannot create '$TARGET'."
    DOCKER_USER="${DOCKER_USER:-$(id -u):$(ls -ldn "$TARGET" | awk '{print $4}')}"
    [[ "$DOCKER_USER" =~ ^[A-Za-z0-9_.-]+(:[A-Za-z0-9_.-]+)?$ ]] || die "Invalid --docker-user '$DOCKER_USER'."
else
    [[ -z "$DOCKER_WORKDIR$DOCKER_USER" ]] || die "--docker-workdir and --docker-user require --docker."
    command -v php >/dev/null 2>&1 || die "PHP CLI is not installed (PHP only in a container? use --docker, see --help)."
fi

if [[ -n "$DOCKER" ]]; then
    # Inside the target, so the container sees the framework source; the name matches the *.frasm-new ignore rule
    WORK="$TARGET/.update-$$.frasm-new"
    mkdir -m 755 "$WORK" || die "Cannot create '$WORK'."
else
    WORK="$(mktemp -d)"
fi
trap 'rm -rf "$WORK"' EXIT
COMMIT=""

if [[ -n "$DOCKER" ]] && ! php_cli -r 'exit(is_dir($argv[1]) ? 0 : 1);' "$(basename "$WORK")" 2>/dev/null; then
    die "'$TARGET' is not mounted at $DOCKER_WORKDIR in the running container '$DOCKER' (check --docker and --docker-workdir)."
fi
php_cli -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "PHP 8.3 or newer is required (found $(php_cli -r 'echo PHP_VERSION;'))."

if [[ -n "$SOURCE" ]]; then
    [[ -f "$SOURCE/bin/install.php" ]] || die "'$SOURCE' is not a Frasm checkout."
    SRC="$(cd "$SOURCE" && pwd)"
    COMMIT="$(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || true)"
    if [[ -n "$DOCKER" ]]; then
        # A checkout outside the target is not visible in the container
        mkdir "$WORK/frasm"
        tar -C "$SRC" --exclude=./.git -cf - . | tar -C "$WORK/frasm" -xf -
        SRC="$WORK/frasm"
    fi
elif [[ -z "${GITHUB_TOKEN:-}" ]] && command -v git >/dev/null 2>&1; then
        step "Fetching Frasm ($REF) from $REPO"
    git clone --quiet --depth 1 --branch "$REF" "$REPO" "$WORK/frasm" || die "git clone failed (private repository? set FRASM_REPO to an SSH URL or GITHUB_TOKEN)."
    SRC="$WORK/frasm"
    COMMIT="$(git -C "$SRC" rev-parse --short HEAD)"
else
    # Tarball download: https://github.com/<owner>/<repo>(.git) → API tarball endpoint
    SLUG="$(echo "$REPO" | sed -E 's#^(https://github.com/|git@github.com:)##; s#\.git$##')"
    URL="https://api.github.com/repos/${SLUG}/tarball/${REF}"
    AUTH=()
    [[ -n "${GITHUB_TOKEN:-}" ]] && AUTH=(-H "Authorization: Bearer ${GITHUB_TOKEN}")

        step "Downloading Frasm ($REF) from $URL"
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

if [[ -z "$DOCKER" ]]; then
    php "$SRC/bin/install.php" --target="$TARGET" --ref="$REF" --commit="$COMMIT" "${PASS_ARGS[@]}"
    exit 0
fi

php_cli "$(basename "$WORK")/frasm/bin/install.php" --target=. --host-target="$(cd "$TARGET" && pwd)" \
    --ref="$REF" --commit="$COMMIT" "${PASS_ARGS[@]}"

# Docker: make (frasm.mk) runs the CLI in the same container from now on; a project's own frasm.mk is kept
if [[ ! -f "$TARGET/frasm.mk" ]]; then
    cat > "$TARGET/frasm.mk" <<MK
# Docker settings of this project (written by install.sh --docker; not versioned).
# make runs \`php\` in this container; make update passes the settings on to install.sh.
DOCKER_CONTAINER = $DOCKER
DOCKER_WORKDIR   = $DOCKER_WORKDIR
DOCKER_USER      = $DOCKER_USER
MK
    step "Docker settings written to frasm.mk (make runs PHP in the container '$DOCKER')"
fi
