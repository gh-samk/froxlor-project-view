#!/usr/bin/env bash
#
# Removes Project View from a Froxlor installation.
#
#   ./uninstall.sh /var/www/froxlor            remove the files only
#   ./uninstall.sh /var/www/froxlor --purge    additionally drop the extension's tables
#
# Without --purge the assignments are kept: whoever removes the extension
# only temporarily does not lose any work.
#
# Run as root, files are deleted as the owner of the Froxlor directory (see
# install.sh and scripts/privileges.sh).

set -euo pipefail
umask 022

TARGET="${1:-}"
PURGE=0
[[ "${2:-}" == "--purge" ]] && PURGE=1

if [[ -z "$TARGET" ]] || [[ ! -f "$TARGET/lib/init.php" ]]; then
	echo "Usage: ./uninstall.sh <froxlor-directory> [--purge]" >&2
	exit 1
fi
TARGET="$(cd "${TARGET%/}" && pwd -P)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

FILES=(
	"admin_projects.php"
	"admin_projects_return.php"
	"actions/admin/settings/190.ext.projectview.php"
	"lib/navigation/50.ext.projectview.php"
	"lib/tablelisting/admin/tablelisting.ext.projectview.php"
	"lib/Froxlor/Ext/ProjectView"
	"templates/ProjectView"
)

# shellcheck source=scripts/privileges.sh
. "$HERE/scripts/privileges.sh"

as_owner() { "$@"; }
if [[ $EUID -eq 0 ]]; then
	bad="$(untrusted_component "$HERE/uninstall.sh" "${SUDO_UID:-}")"
	if [[ -n "$bad" ]]; then
		echo "ERROR: the package is not safe to run as root: $bad is a link or" >&2
		echo "owned/writable by another user. Clone it outside any web root (e.g. /opt)." >&2
		exit 1
	fi
	OWNER_UID="$(file_uid "$TARGET")"
	OWNER_GID="$(file_gid "$TARGET")"
	if [[ "$OWNER_UID" != "0" ]]; then
		as_owner() { drop_to "$OWNER_UID" "$OWNER_GID" "$@"; }
	else
		# Root deletes itself only where nobody else can plant links.
		for path in "${FILES[@]}"; do
			unsafe="$(untrusted_component "$(dirname "$TARGET/$path")")"
			if [[ -n "$unsafe" && -e "$TARGET/$path" ]]; then
				echo "ERROR: $TARGET belongs to root, but $unsafe is a link or owned/writable" >&2
				echo "by another user or group. Remove the files by hand." >&2
				exit 1
			fi
		done
	fi
fi

# A symbolic link among the parent directories would redirect rm -rf
# elsewhere. The last component itself may be a link: rm removes the link,
# not its target.
parents_safe() {
	local rel="$1" current="$TARGET" part
	IFS='/' read -r -a parts <<< "$(dirname "$rel")"
	for part in "${parts[@]}"; do
		[[ "$part" == "." ]] && continue
		current="$current/$part"
		[[ -L "$current" ]] && return 1
	done
	return 0
}

echo "Removing files"
for path in "${FILES[@]}"; do
	if [[ -e "$TARGET/$path" || -L "$TARGET/$path" ]]; then
		if ! parents_safe "$path"; then
			echo "  SKIPPED (symbolic link in the path): $path" >&2
			continue
		fi
		as_owner rm -rf -- "${TARGET:?}/$path" </dev/null
		echo "  removed: $path"
	fi
done
# lib/Froxlor/Ext/ is shared with other extensions: only remove it when empty.
if [[ -d "$TARGET/lib/Froxlor/Ext" && ! -L "$TARGET/lib/Froxlor/Ext" ]] \
	&& [[ -z "$(ls -A "$TARGET/lib/Froxlor/Ext" 2>/dev/null)" ]]; then
	as_owner rmdir "$TARGET/lib/Froxlor/Ext" </dev/null >/dev/null 2>&1 || true
fi

if [[ -d "$TARGET/cache" && ! -L "$TARGET/cache" ]]; then
	as_owner find "$TARGET/cache" -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} + </dev/null 2>/dev/null || true
fi

echo
echo "Database, customer sites and mail configuration"
if [[ -L "$TARGET/lib/userdata.inc.php" ]]; then
	echo "  ERROR: lib/userdata.inc.php is a symbolic link; it is not read." >&2
	echo "  Run by hand: DELETE FROM cronjobs_run WHERE module = 'ext/projectview';" >&2
elif command -v php >/dev/null 2>&1 && [[ -r "$TARGET/lib/userdata.inc.php" ]]; then
	# The output contains paths from customer data: strip control characters.
	if [[ $PURGE -eq 1 ]]; then
		php "$HERE/migrations/remove.php" "$TARGET" --purge </dev/null 2>&1 | sanitize_output
	else
		php "$HERE/migrations/remove.php" "$TARGET" </dev/null 2>&1 | sanitize_output
	fi
else
	echo "  Credentials not readable or php missing — please run by hand:" >&2
	echo "    DELETE FROM cronjobs_run WHERE module = 'ext/projectview';" >&2
fi

echo
echo "Done. WordPress backups below the configured backup directory"
echo "(default /var/backups/froxlor-project-view/) are kept; nobody deletes them automatically."
