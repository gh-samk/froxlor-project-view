<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use Froxlor\Settings;
use RuntimeException;

/**
 * Runs WP-CLI and file operations for exactly one WordPress installation —
 * as the customer's system user, never as root.
 *
 * Runs exclusively in the Froxlor cron (root). The panel's web process only
 * creates jobs and never executes code itself.
 *
 * Security boundaries:
 * - setpriv switches to the customer's uid/gid (in Froxlor: uid = gid = guid)
 *   and drops all supplementary groups. Whatever WordPress code WP-CLI loads
 *   therefore has exactly the customer's rights — the same as their website.
 *   --no-new-privs additionally prevents customer code from regaining
 *   privileges through setuid programs.
 * - root never enters the installation directory. The process starts in "/",
 *   and only after the privilege drop does the customer process change into
 *   the installation (cd -P) and verify that its physical path is exactly the
 *   one checked before. If the customer swapped a path component for a
 *   symbolic link in the meantime — for instance into another customer's
 *   installation — the call is aborted. Everything after that works relative
 *   to this working directory, so a later swap of a name no longer matters.
 * - Customer processes get a scrubbed environment (env -i) with an explicit
 *   allowlist: nothing from root's cron environment reaches customer code.
 * - Before switching to the customer, all inherited file descriptors except
 *   0/1/2 are closed. Otherwise the parent process (root) would hand its open
 *   Froxlor database connection to customer code — WP-CLI loads the
 *   customer's wp-config.php, which could send SQL to the panel database over
 *   that descriptor.
 * - Every write into customer directories also runs as the customer. If root
 *   wrote there, a customer could plant a symbolic link beforehand and make
 *   root overwrite an arbitrary system file. Backups are read by the customer
 *   too (tar as the customer); only the target file in the root-owned
 *   directory is opened by the outer shell, and its size can be capped.
 * - --skip-plugins and --skip-themes: maintenance needs no plugin or theme
 *   code. That shrinks the attack surface and helps exactly when a plugin has
 *   broken the site.
 * - After realpath, the path must lie inside the customer directory.
 * - Every call has a time limit, a hard deadline and caps on the stdout and
 *   stderr that are read — a customer process cannot hold up the cron or fill
 *   its memory. Known limit: `timeout` and the deadline kill the process
 *   group of the call. A customer process that detaches into a new session
 *   (setsid) escapes both and keeps running — with the customer's own rights
 *   only, like any process the customer could start from their website. The
 *   cron neither waits for it nor reads from it.
 */
class WordpressCli
{
	/** Default time limit for a call, in seconds. */
	public const TIME_LIMIT = 600;

	/** Time limit for short, read-only queries (version, lists, options). */
	public const QUERY_TIME_LIMIT = 120;

	/** Hard deadline after which the process and its group are killed. */
	private const DEADLINE_GRACE = 60;

	/** At most this much stdout is read, then the call is aborted. */
	private const OUTPUT_MAX = 2097152;

	/** At most this much stderr is kept; the rest is read and discarded. */
	private const ERROR_MAX = 1048576;

	/** Parent directory of the WP-CLI home directories; owned by root, 0711. */
	private const HOME_BASE = '/var/lib/froxlor-project-view/wpcli';

	/** The only environment customer processes get (plus HOME and WP-CLI variables). */
	/** Address space (bytes) and largest file a customer process may use. */
	private const MEMORY_LIMIT = 4294967296;
	private const FILE_SIZE_LIMIT = 8589934592;

	private const ENVIRONMENT = [
		'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
		'LANG=C.UTF-8',
		'LC_ALL=C.UTF-8',
	];

	/** Exit code of ENTER_SCRIPT when the installation path does not check out. */
	public const EXIT_PATH_CHANGED = 120;

	/**
	 * Runs as the customer, after the privilege drop: change into the
	 * installation without following symbolic links blindly, and refuse if
	 * the physical working directory is not exactly the path checked by root.
	 * $1 is the expected path, the rest is the command.
	 */
	private const ENTER_SCRIPT = 'cd -P -- "$1" 2>/dev/null && [ "$(pwd -P)" = "$1" ] '
		. '|| { echo "froxlor-project-view: installation path changed or contains a symbolic link" >&2; exit 120; }; '
		. 'shift; exec "$@"';

	private string $path;
	private int $uid;
	private int $gid;
	private string $wpcli;
	private string $home;

	private function __construct(string $path, int $uid, int $gid, string $wpcli)
	{
		$this->path = $path;
		$this->uid = $uid;
		$this->gid = $gid;
		$this->wpcli = $wpcli;
		$this->home = self::HOME_BASE . '/' . $uid;
	}

	/**
	 * Where is WP-CLI? The setting takes precedence, otherwise the usual places.
	 * null means: not found — jobs would fail.
	 */
	public static function wpcliPath(): ?string
	{
		$configured = trim((string)Settings::Get('ext_projectview.wpcli_path'));
		$candidates = $configured !== '' ? [$configured] : ['/usr/local/bin/wp', '/usr/bin/wp', '/opt/wp-cli/wp'];
		foreach ($candidates as $candidate) {
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Builds the runner for one row of ext_projectview_wp_installs.
	 *
	 * @throws RuntimeException with a message that can go into the job log as is
	 */
	public static function forInstall(array $install): self
	{
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			throw new RuntimeException(Lang::t('WordPress jobs only run in the Froxlor cron job as root.'));
		}
		foreach (['setpriv', 'prlimit', 'timeout', 'bash', 'env', 'head'] as $tool) {
			if (!self::exists($tool)) {
				throw new RuntimeException(Lang::t('The tool “%s” is missing on the server.', $tool));
			}
		}
		$wpcli = self::wpcliPath();
		if ($wpcli === null) {
			throw new RuntimeException(Lang::t('WP-CLI was not found. Enter the path under Settings › Project View.'));
		}

		$stmt = Database::prepare("
			SELECT `c`.`guid`, `c`.`documentroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			INNER JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`id` = :id
		");
		$customer = Database::pexecute_first($stmt, ['id' => (int)$install['domainid']]);
		// Froxlor assigns customer IDs from 10000 upwards. Anything below 1000
		// would be a system user — and uid 0 would be root. Neither is ever run.
		if (!$customer || (int)$customer['guid'] < 1000) {
			throw new RuntimeException(Lang::t('No valid customer was found for the installation.'));
		}

		$base = realpath(rtrim((string)$customer['documentroot'], '/'));
		$path = realpath((string)$install['path']);
		if ($base === false || $path === false || !str_starts_with($path . '/', $base . '/')) {
			throw new RuntimeException(Lang::t('The installation path is not inside the customer directory.'));
		}
		if (!is_file($path . '/wp-config.php') && !is_file(dirname($path) . '/wp-config.php')) {
			throw new RuntimeException(Lang::t('There is no wp-config.php in the installation.'));
		}

		return new self($path, (int)$customer['guid'], (int)$customer['guid'], $wpcli);
	}

	/** The physical installation path as checked by forInstall() (realpath). */
	public function path(): string
	{
		return $this->path;
	}

	public function uid(): int
	{
		return $this->uid;
	}

	/**
	 * Runs WP-CLI as the customer, inside the installation directory
	 * (--path=. after the verified cd).
	 *
	 * $gzipTarget writes stdout gzip-compressed into this file — meant for
	 * database backups. The redirect is opened by the outer shell as root, so
	 * the file must lie in a root-owned directory. The path is escaped here;
	 * it must not come from customer input. $targetMaxBytes caps the size of
	 * that file (see 'limit_exceeded' in the result).
	 *
	 * @return array{code:int, out:string, err:string, limit_exceeded:bool}
	 */
	public function wp(array $arguments, ?string $gzipTarget = null, ?int $timeLimit = null, ?int $targetMaxBytes = null): array
	{
		$this->prepareHome();
		$seconds = $timeLimit ?? self::TIME_LIMIT;
		$parts = array_merge(
			$this->asCustomerPrefix($seconds, [
				'WP_CLI_CACHE_DIR=' . $this->home . '/cache',
				'WP_CLI_DISABLE_AUTO_CHECK_UPDATE=1',
			]),
			[
				$this->wpcli,
				'--path=.',
				'--skip-plugins',
				'--skip-themes',
				'--no-color',
			],
			$arguments
		);
		$line = self::line($parts);
		if ($gzipTarget !== null) {
			$line .= ' | gzip' . self::targetRedirect($gzipTarget, $targetMaxBytes);
		}

		return self::finish(self::start($line, null, $seconds), $gzipTarget, $targetMaxBytes);
	}

	/**
	 * Runs an arbitrary command as the customer, inside the installation
	 * directory — relative paths in $command refer to the installation.
	 * Optionally with input on stdin.
	 *
	 * $outputTarget redirects stdout into this file (opened by the outer root
	 * shell): this way a tar running as the customer writes its archive into a
	 * root-owned backup directory without root ever reading the customer's
	 * files itself. The path is escaped and must not come from customer input.
	 * $targetMaxBytes caps the size of that file.
	 *
	 * @return array{code:int, out:string, err:string, limit_exceeded:bool}
	 */
	public function asCustomer(array $command, ?string $input = null, ?string $outputTarget = null, ?int $timeLimit = null, ?int $targetMaxBytes = null): array
	{
		$this->prepareHome();
		$seconds = $timeLimit ?? self::TIME_LIMIT;
		$line = self::line(array_merge($this->asCustomerPrefix($seconds), $command));
		if ($outputTarget !== null) {
			$line .= self::targetRedirect($outputTarget, $targetMaxBytes);
		}

		return self::finish(self::start($line, $input, $seconds), $outputTarget, $targetMaxBytes);
	}

	/**
	 * Runs a command as root, without a shell in between, in "/". Only for
	 * targets owned by root (backup directory) — never for customer files;
	 * those are read exclusively by the customer (asCustomer).
	 *
	 * @return array{code:int, out:string, err:string}
	 */
	public static function asRoot(array $command, ?int $timeLimit = null): array
	{
		return self::start(self::line($command), null, $timeLimit ?? self::TIME_LIMIT);
	}

	/**
	 * timeout → setpriv (privilege drop) → env -i (scrubbed environment) →
	 * bash ENTER_SCRIPT (verified cd into the installation) → command.
	 */
	private function asCustomerPrefix(int $seconds, array $extraEnvironment = []): array
	{
		return array_merge(
			[
				'timeout', '--kill-after=30', (string)$seconds,
				'setpriv', '--reuid=' . $this->uid, '--regid=' . $this->gid, '--clear-groups', '--no-new-privs', '--',
				// WP-CLI runs the customer's own code outside the FPM pool's limits:
				// cap memory, CPU time and file size, and forbid core dumps.
				'prlimit', '--as=' . self::MEMORY_LIMIT, '--cpu=' . $seconds, '--fsize=' . self::FILE_SIZE_LIMIT, '--core=0', '--',
				'env', '-i',
			],
			self::ENVIRONMENT,
			['HOME=' . $this->home],
			$extraEnvironment,
			['bash', '-c', self::ENTER_SCRIPT, 'froxlor-project-view', $this->path]
		);
	}

	/**
	 * Redirect of stdout into a root-owned file. With a cap, at most one byte
	 * more than allowed reaches the file (head -c), and finish() reports the
	 * overflow — the producer gets SIGPIPE and the call fails via pipefail.
	 */
	private static function targetRedirect(string $target, ?int $maxBytes): string
	{
		if ($maxBytes === null) {
			return ' > ' . escapeshellarg($target);
		}

		return ' | head -c ' . (max(0, $maxBytes) + 1) . ' > ' . escapeshellarg($target);
	}

	/** Adds 'limit_exceeded' and turns the path check of ENTER_SCRIPT into a readable message. */
	private static function finish(array $result, ?string $target, ?int $maxBytes): array
	{
		clearstatcache();
		$result['limit_exceeded'] = $target !== null && $maxBytes !== null && (int)@filesize($target) > $maxBytes;
		if ($result['code'] === self::EXIT_PATH_CHANGED && str_contains($result['err'], 'froxlor-project-view: installation path changed')) {
			$result['err'] = Lang::t('The installation path changed or contains a symbolic link — aborted.');
		}

		return $result;
	}

	/**
	 * Home directory for WP-CLI (cache, configuration).
	 *
	 * Lives below a root-owned parent directory: a customer cannot create
	 * anything there in advance — in particular no symbolic link that would
	 * redirect the chown to a foreign file.
	 */
	private function prepareHome(): void
	{
		if (is_link(self::HOME_BASE) || is_link(dirname(self::HOME_BASE))) {
			throw new RuntimeException(Lang::t('The WP-CLI home directory is a symbolic link — aborted.'));
		}
		if (!is_dir(self::HOME_BASE) && !mkdir(self::HOME_BASE, 0711, true)) {
			throw new RuntimeException(Lang::t('Could not create %s.', self::HOME_BASE));
		}
		@chmod(dirname(self::HOME_BASE), 0711);
		@chmod(self::HOME_BASE, 0711);

		if (is_link($this->home)) {
			throw new RuntimeException(Lang::t('The WP-CLI home directory is a symbolic link — aborted.'));
		}
		if (!is_dir($this->home) && !mkdir($this->home, 0700)) {
			throw new RuntimeException(Lang::t('Could not create %s.', $this->home));
		}
		chown($this->home, $this->uid);
		chgrp($this->home, $this->gid);
	}

	/**
	 * Starts a command line in bash with pipefail, with "/" as the working
	 * directory — root never changes into a customer directory.
	 *
	 * It is prefixed with a loop that closes all inherited descriptors except
	 * 0/1/2 before the actual command (and with it setpriv and the customer
	 * code) starts. Redirects in the command open their files afterwards and
	 * are not affected.
	 *
	 * stdout and stderr are both read through pipes with stream_select, so
	 * neither can block the other when its buffer is full, and no temporary
	 * file is needed. stdout is capped (the call is aborted beyond it),
	 * stderr is capped too (anything beyond is read and discarded). Reading
	 * has a deadline: if a customer process hangs or floods the output, it is
	 * killed together with its process group instead of holding up the cron.
	 */
	private static function start(string $line, ?string $input, int $seconds): array
	{
		$full = 'for __f in /proc/self/fd/*; do __n=${__f##*/}; '
			. 'case $__n in 0|1|2|*[!0-9]*|"") ;; *) eval "exec $__n>&-" 2>/dev/null ;; esac; done; '
			. 'exec ' . $line;
		$process = proc_open(
			['bash', '-o', 'pipefail', '-c', $full],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			'/'
		);
		if (!is_resource($process)) {
			throw new RuntimeException(Lang::t('The command could not be started.'));
		}
		if ($input !== null) {
			fwrite($pipes[0], $input);
		}
		fclose($pipes[0]);

		// Own read loop with a deadline instead of stream_get_contents: a
		// customer process that keeps stdout open would otherwise block
		// stream_get_contents forever.
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$buffers = [1 => '', 2 => ''];
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		$deadline = microtime(true) + $seconds + self::DEADLINE_GRACE;
		$aborted = false;
		while ($open !== []) {
			$read = array_values($open);
			$write = $except = [];
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				$aborted = true;
				break;
			}
			$ready = @stream_select($read, $write, $except, (int)floor($remaining), (int)(($remaining - floor($remaining)) * 1e6));
			if ($ready === false) {
				break;
			}
			foreach ($read as $stream) {
				$fd = $stream === $pipes[1] ? 1 : 2;
				$chunk = fread($stream, 65536);
				if ($chunk === false || $chunk === '') {
					if (feof($stream)) {
						unset($open[$fd]);
					}
					continue;
				}
				if ($fd === 1) {
					$buffers[1] .= $chunk;
					if (strlen($buffers[1]) > self::OUTPUT_MAX) {
						$buffers[1] = substr($buffers[1], 0, self::OUTPUT_MAX);
						$aborted = true;
						break 2;
					}
				} elseif (strlen($buffers[2]) < self::ERROR_MAX) {
					$buffers[2] = substr($buffers[2] . $chunk, 0, self::ERROR_MAX);
				}
			}
		}
		fclose($pipes[1]);
		fclose($pipes[2]);

		if ($aborted) {
			self::kill($process);
		}
		$code = proc_close($process);
		// An aborted call (deadline or output flood) always reports 124, like
		// timeout does, whatever the killed process returned.
		if ($aborted) {
			$code = 124;
		}

		return ['code' => $code, 'out' => trim($buffers[1]), 'err' => trim($buffers[2])];
	}

	/**
	 * Kills the process and its children. The bash wrapper execs `timeout`,
	 * which leads its own process group; the whole group is hit only if the
	 * process really leads it — otherwise a signal to -pid could hit a
	 * foreign group. Processes that moved into a new session (setsid) are not
	 * reached; see the class comment.
	 */
	private static function kill($process): void
	{
		$status = proc_get_status($process);
		$pid = (int)($status['pid'] ?? 0);
		if ($pid > 0 && function_exists('posix_getpgid') && function_exists('posix_kill')) {
			if (@posix_getpgid($pid) === $pid) {
				@posix_kill(-$pid, 9);
			}
		}
		@proc_terminate($process, 9);
	}

	private static function line(array $parts): string
	{
		return implode(' ', array_map('escapeshellarg', $parts));
	}

	private static function exists(string $tool): bool
	{
		return trim((string)shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null')) !== '';
	}
}
