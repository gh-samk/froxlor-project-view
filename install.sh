#!/usr/bin/env bash
#
# Installs Project View into an existing Froxlor 2.x installation.
#
#   ./install.sh /var/www/froxlor
#   ./install.sh /var/www/froxlor --dry-run            write nothing, only show
#   ./install.sh /var/www/froxlor --skip-migrations    files only, no database
#
# Only new files are copied — no Froxlor file is modified. Run this script
# again after every Froxlor update.
#
# Run as root, files inside the Froxlor directory are written as the owner of
# that directory, never as root: the panel user owns the directory and could
# otherwise plant symbolic links that make root write elsewhere. The drop runs
# in a new session without a terminal and with a reset environment (see
# scripts/privileges.sh). Root writes itself only when the Froxlor directory
# and every directory on the way are root-owned and not writable by others.

set -euo pipefail
umask 022

TARGET="${1:-}"
shift || true

DRY_RUN=0
MIGRATE=1
for opt in "$@"; do
	case "$opt" in
		--dry-run)          DRY_RUN=1 ;;
		--skip-migrations)  MIGRATE=0 ;;
		*) echo "Unknown option: $opt" >&2; exit 1 ;;
	esac
done

if [[ -z "$TARGET" ]]; then
	cat >&2 <<'USAGE'
Usage: ./install.sh <froxlor-directory> [--dry-run] [--skip-migrations]

Example:
    ./install.sh /var/www/froxlor
USAGE
	exit 1
fi

TARGET="${TARGET%/}"
if [[ ! -f "$TARGET/lib/init.php" ]] || [[ ! -d "$TARGET/templates/Froxlor" ]]; then
	echo "ERROR: '$TARGET' does not look like a Froxlor installation." >&2
	exit 1
fi
TARGET="$(cd "$TARGET" && pwd -P)"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
VERSION="$(cat "$HERE/VERSION" 2>/dev/null || echo "unknown")"

# PHP 8.1 or newer: the extension uses PHP 8 features. Froxlor 2.3 itself
# still allows 7.4, so this is checked here and not left to a parse error.
if ! command -v php >/dev/null 2>&1; then
	echo "ERROR: php not found. Project View requires PHP 8.1 or newer." >&2
	exit 1
fi
PHP_ID="$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)"
if [[ ! "$PHP_ID" =~ ^[0-9]+$ ]] || (( PHP_ID < 80100 )); then
	echo "ERROR: PHP 8.1 or newer is required (found: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown))." >&2
	exit 1
fi

# Exactly these paths belong to the extension. Listed one by one instead of a
# blanket sync: this way the script can never overwrite or delete anything
# that belongs to Froxlor.
FILES=(
	"admin_projects.php"
	"admin_projects_return.php"
	"actions/admin/settings/190.ext.projectview.php"
	"lib/navigation/50.ext.projectview.php"
	"lib/tablelisting/admin/tablelisting.ext.projectview.php"
	"lib/Froxlor/Ext/ProjectView/"
	"templates/ProjectView/"
)
for path in "${FILES[@]}"; do
	if [[ ! -e "$HERE/${path%/}" ]] || [[ -L "$HERE/${path%/}" ]]; then
		echo "ERROR: $path is missing from the package." >&2
		exit 1
	fi
done

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

# as_owner CMD...: run CMD as the user that does the writing (see header).
as_owner() { "$@"; }
if [[ $EUID -eq 0 ]]; then
	# Root runs this package: it must not live where someone else can change it.
	bad="$(untrusted_component "$HERE/install.sh" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$TARGET")"
	OWNER_GID="$(file_gid "$TARGET")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
		echo "Writing as uid $OWNER_UID (owner of $TARGET)"
	else
		# Root-owned: root writes itself only if nobody else can write anywhere
		# on the way — every component from / down to each target's parent,
		# and every directory below an existing target directory.
		unsafe=""
		for path in "${FILES[@]}"; do
			parent="$(dirname "$TARGET/${path%/}")"
			[[ -e "$parent" ]] || parent="$TARGET"
			unsafe="$(untrusted_component "$parent")"
			[[ -n "$unsafe" ]] && break
			if [[ -d "$TARGET/${path%/}" && ! -L "$TARGET/${path%/}" ]]; then
				unsafe="$(find "$TARGET/${path%/}" \( -type l -o \( -type d \( ! -user 0 -o -perm -0002 -o \( -perm -0020 ! -group 0 \) \) \) \) -print -quit 2>/dev/null)"
				[[ -n "$unsafe" ]] && break
			fi
		done
		if [[ -n "$unsafe" ]]; then
			echo "ERROR: $TARGET belongs to root, but $unsafe" >&2
			echo "is a link or owned/writable by another user or group. Root does not write" >&2
			echo "where a less privileged user can plant links. Either give the whole Froxlor" >&2
			echo "directory to the panel's system user (the installer then writes as that" >&2
			echo "user), or make every directory on the way owned by root and not" >&2
			echo "group/world-writable." >&2
			exit 1
		fi
		echo "Writing as root ($TARGET is root-owned and not writable by others)"
	fi
fi

# A target whose path inside the Froxlor directory contains a symbolic link is
# refused: nothing in the package is a link, so any link there was put there
# by someone else. Checked for every target before anything is written, so a
# refusal leaves no half installation behind.
check_path() {
	local rel="${1%/}" current="$TARGET" part
	IFS='/' read -r -a parts <<< "$rel"
	for part in "${parts[@]}"; do
		current="$current/$part"
		if [[ -L "$current" ]]; then
			echo "ERROR: $current is a symbolic link — refusing to write through it." >&2
			exit 1
		fi
	done
	if [[ -d "$current" ]] && [[ -n "$(find "$current" -type l -print -quit)" ]]; then
		echo "ERROR: $current contains a symbolic link — refusing to write into it." >&2
		exit 1
	fi
}
for path in "${FILES[@]}"; do
	check_path "$path"
done

echo "Project View $VERSION"
echo "Target: $TARGET"
[[ $DRY_RUN -eq 1 ]] && echo "(dry run, nothing is written)"
echo

# Copied with tar instead of rsync: tar is part of every base system, rsync is
# often missing on lean servers. The archive is read by the calling user and
# unpacked as the owner of the Froxlor directory, without taking over owners
# or modes from the repository.
echo "Files"
for path in "${FILES[@]}"; do
	if [[ $DRY_RUN -eq 1 ]]; then
		echo "  would copy: $path"
		continue
	fi
	( cd "$HERE" && tar cf - "${path%/}" ) \
		| as_owner tar -C "$TARGET" --no-same-owner --no-same-permissions -xf -
	echo "  $path"
done

# Twig caches compiled templates; without clearing, a change would run
# against the old compiled version. find does not follow symbolic links.
if [[ $DRY_RUN -eq 0 && -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	if as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null; then
		echo "  Twig cache cleared"
	else
		echo "  WARNING: could not clear $TARGET/cache completely — clear it by hand."
	fi
fi

if [[ $MIGRATE -eq 0 ]]; then
	echo
	echo "Database skipped (--skip-migrations)."
	echo "Apply the migrations later with:"
	echo "    php $HERE/migrations/apply.php $TARGET"
	exit 0
fi

echo
echo "Database"

# Via PHP/PDO instead of a MySQL command line client: PHP is present on every
# Froxlor server, a mysql client not necessarily. The connection uses exactly
# the options from Froxlor's userdata.inc.php, including TLS; the file is
# parsed, not executed (see migrations/connection.php). Its output may contain
# values chosen by the panel user and is stripped of control characters.
if [[ -L "$TARGET/lib/userdata.inc.php" ]]; then
	echo "  ERROR: $TARGET/lib/userdata.inc.php is a symbolic link; it is not read." >&2
	exit 1
fi
if [[ ! -r "$TARGET/lib/userdata.inc.php" ]]; then
	echo "  ERROR: $TARGET/lib/userdata.inc.php is not readable." >&2
	echo "  Run the script as root, or use --skip-migrations." >&2
	exit 1
fi

MIG_OPTS=()
[[ $DRY_RUN -eq 1 ]] && MIG_OPTS+=(--dry-run)
php "$HERE/migrations/apply.php" "$TARGET" "${MIG_OPTS[@]+"${MIG_OPTS[@]}"}" </dev/null 2>&1 | sanitize_output

echo
echo "Done. Project View appears in the admin area under \"Projects\";"
echo "its settings are under Settings > Project View."
echo
echo "Check that no Froxlor file was touched:"
echo "    cd $TARGET && git status"
