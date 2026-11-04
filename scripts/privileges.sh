# Privilege helpers, sourced by install.sh and uninstall.sh (bash).
#
# The panel's system user can write inside the Froxlor directory. When the
# scripts run as root, nothing that user controls may influence root: root
# either works as that user (drop_to) or only where no one else can write
# (untrusted_component).

# Owner and group of a path itself (a link is not followed).
file_uid() { stat -c %u "$1" 2>/dev/null || stat -f %u "$1"; }
file_gid() { stat -c %g "$1" 2>/dev/null || stat -f %g "$1"; }

# Strips control characters (except tab and newline) from a stream that may
# contain text chosen by the panel user before it reaches root's terminal.
sanitize_output() { LC_ALL=C tr -d '\000-\010\013-\037\177'; }

# untrusted_component PATH [UID...]
# Prints the first component of PATH, walking up to /, that root must not
# trust: a symbolic link, a file or directory owned by anyone but root (or the
# given UIDs), or one that is writable by others or by a group other than
# root's. Directories with the sticky bit (like /tmp) are accepted: nobody else
# can replace a root-owned entry in them. Prints nothing if all are fine.
untrusted_component() {
	local path="$1" uid
	shift
	local owners=(! -user 0)
	for uid in "$@"; do
		[[ -n "$uid" ]] && owners+=(! -user "$uid")
	done
	while :; do
		if [[ -L "$path" ]] || [[ ! -e "$path" ]] \
			|| [[ -n "$(find "$path" -maxdepth 0 \( \( "${owners[@]}" \) \
				-o \( -perm -0002 ! -perm -1000 \) \
				-o \( -perm -0020 ! -group 0 ! -perm -1000 \) \) -print 2>/dev/null)" ]]; then
			printf '%s\n' "$path"
			return 0
		fi
		[[ "$path" == "/" ]] && return 0
		path="$(dirname "$path")"
	done
}

# Closes every file descriptor above 2 of the current (sub)shell, so a
# dropped process inherits nothing of root's but stdin/stdout/stderr.
close_extra_fds() {
	local fd
	[[ -d "/proc/$BASHPID/fd" ]] || return 0
	for fd in "/proc/$BASHPID/fd/"*; do
		fd="${fd##*/}"
		if [[ "$fd" =~ ^[0-9]+$ ]] && (( fd > 2 )); then
			eval "exec $fd>&-" 2>/dev/null || true
		fi
	done
}

# drop_to UID GID CMD...
# Runs CMD as that user: in a new session without a controlling terminal (no
# TIOCSTI keystroke injection into root's terminal), with a reset environment,
# without root's file descriptors above 2, and with its output (stdout and
# stderr, merged) stripped of control characters. stdin is whatever the
# caller passes - use </dev/null unless CMD reads a pipe.
drop_to() {
	local uid="$1" gid="$2"
	shift 2
	local runner=()
	if ! command -v setsid >/dev/null 2>&1; then
		echo "ERROR: setsid (util-linux) not found; cannot drop root privileges safely." >&2
		return 1
	fi
	if command -v setpriv >/dev/null 2>&1; then
		runner=(setpriv --reuid="$uid" --regid="$gid" --init-groups --reset-env --)
	elif command -v runuser >/dev/null 2>&1; then
		runner=(runuser -u "$(id -nu "$uid")" -- env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin)
	else
		echo "ERROR: neither setpriv nor runuser (util-linux) found; cannot drop root privileges." >&2
		return 1
	fi
	(
		close_extra_fds
		# Never start the new user in the caller's directory (usually /root): it
		# cannot enter it, and tools like find fail on their initial directory.
		cd / || exit 1
		exec setsid --wait "${runner[@]}" "$@"
	) 2>&1 | sanitize_output
}
