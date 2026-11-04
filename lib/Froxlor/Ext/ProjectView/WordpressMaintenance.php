<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use Froxlor\Settings;
use RuntimeException;

/**
 * The work behind the WordPress jobs: check, back up, update, set up and
 * remove autologin.
 *
 * Runs only in the Froxlor cron. Every step writes into the job log that
 * Project View shows with the job. Updates always start with a backup — if
 * that fails, nothing is updated.
 */
class WordpressMaintenance
{
	/** File names inside a backup directory. */
	public const BACKUP_DATABASE_FILE = 'database.sql.gz';
	public const BACKUP_FILES_FILE = 'files.tar.gz';

	/**
	 * Space that must stay free on the backup file system, in bytes. A backup
	 * never fills the disk beyond this reserve: each stream is capped to the
	 * free space minus the reserve.
	 */
	public const BACKUP_RESERVE = 268435456;

	/** Time limit for verify-checksums, which hashes every file and downloads checksums. */
	private const VERIFY_TIME_LIMIT = 300;

	private array $install;
	private WordpressCli $cli;
	private array $log = [];
	private ?string $backupPath = null;

	public function __construct(array $install, WordpressCli $cli)
	{
		$this->install = $install;
		$this->cli = $cli;
	}

	/**
	 * @return array{0:bool, 1:string, 2:?string} success, log, backup path
	 */
	public function run(string $type): array
	{
		try {
			switch ($type) {
				case 'check':
					$this->check();
					break;
				case 'backup':
					$this->backup();
					break;
				case 'update_core':
					$this->backup();
					$this->core();
					$this->check();
					break;
				case 'update_plugins':
					$this->backup();
					$this->wp(['plugin', 'update', '--all']);
					$this->check();
					break;
				case 'update_themes':
					$this->backup();
					$this->wp(['theme', 'update', '--all']);
					$this->check();
					break;
				case 'update_all':
					$this->backup();
					$this->core();
					$this->wp(['plugin', 'update', '--all']);
					$this->wp(['theme', 'update', '--all']);
					$this->check();
					break;
				case 'login_install':
					$this->installLogin();
					break;
				case 'login_remove':
					$this->removeLogin();
					break;
				default:
					throw new RuntimeException(Lang::t('Unknown job: %s', $type));
			}
			$this->note(Lang::t('Done.'));

			return [true, $this->text(), $this->backupPath];
		} catch (\Throwable $e) {
			$this->note(Lang::t('ERROR: %s', $e->getMessage()));
			if ($this->backupPath !== null) {
				$this->note(Lang::t('The backup of the previous state is at %s.', $this->backupPath));
			}

			return [false, $this->text(), $this->backupPath];
		}
	}

	private function core(): void
	{
		$this->wp(['core', 'update']);
		// After a core update the database schema may need to catch up;
		// WordPress would otherwise demand it on the next admin page view.
		$this->wp(['core', 'update-db']);
	}

	/**
	 * Version, available updates, administrators and the site address.
	 */
	private function check(): void
	{
		$version = $this->wp(['core', 'version'], true, false, WordpressCli::QUERY_TIME_LIMIT)['out'];
		$core = $this->jsonList(['core', 'check-update']);
		$plugins = $this->jsonList(['plugin', 'list', '--update=available', '--fields=name,version,update_version']);
		$themes = $this->jsonList(['theme', 'list', '--update=available', '--fields=name,version,update_version']);
		$admins = array_values(array_filter(
			$this->jsonList(['user', 'list', '--role=administrator', '--field=user_login']),
			'is_string'
		));
		$siteurl = $this->wp(['option', 'get', 'siteurl'], true, false, WordpressCli::QUERY_TIME_LIMIT)['out'];
		// The address comes from the customer's database: only a strictly
		// validated, rebuilt URL is stored (null otherwise) — it ends up in
		// links and in the autologin redirect.
		$siteurl = WordpressLogin::normalizeSiteUrl($siteurl);
		if ($siteurl !== null && strlen($siteurl) > 255) {
			$siteurl = null;
		}

		// check-update lists minor and major updates separately; the highest counts.
		$coreNew = null;
		foreach ($core as $entry) {
			$v = (string)($entry['version'] ?? '');
			if ($v !== '' && ($coreNew === null || version_compare($v, $coreNew, '>'))) {
				$coreNew = $v;
			}
		}

		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_installs` SET
				`version` = :version, `siteurl` = :siteurl, `admin_users` = :admins,
				`update_core` = :core, `update_plugins` = :plugins, `update_themes` = :themes,
				`update_details` = :details, `checked_at` = :time
			WHERE `id` = :id
		");
		Database::pexecute($stmt, [
			'version' => substr($version, 0, 32) ?: null,
			'siteurl' => $siteurl,
			'admins' => json_encode($admins),
			'core' => $coreNew,
			'plugins' => count($plugins),
			'themes' => count($themes),
			'details' => json_encode(['plugins' => $plugins, 'themes' => $themes]),
			'time' => time(),
			'id' => (int)$this->install['id'],
		]);
		$this->install['admin_users'] = json_encode($admins);

		$this->note(Lang::t(
			'WordPress %s · Core update: %s · %d plugin and %d theme updates · Administrators: %s',
			$version,
			$coreNew ?? Lang::t('none available'),
			count($plugins),
			count($themes),
			$admins ? implode(', ', $admins) : Lang::t('none')
		));

		$this->integrity();
	}

	/**
	 * Modified, added and missing files in core and plugins (checksums from
	 * wordpress.org) and PHP files in uploads.
	 *
	 * A finding does not fail the job: the result is stored and shown in the
	 * WordPress box. Even if wordpress.org is unreachable, the check carries
	 * on and only records what could not be verified.
	 */
	private function integrity(): void
	{
		$this->note('$ wp core verify-checksums');
		$core = $this->cli->wp(['core', 'verify-checksums'], null, self::VERIFY_TIME_LIMIT);
		$this->note('$ wp plugin verify-checksums --all');
		$plugins = $this->cli->wp(['plugin', 'verify-checksums', '--all', '--format=json'], null, self::VERIFY_TIME_LIMIT);
		// Reads only directories and file attributes, as root — but without
		// opening a customer file or following a link. path() is the physical
		// path checked by forInstall(); a symbolic link swapped in below it
		// since then makes the scan refuse instead of following it.
		$uploads = WordpressIntegrity::findUploadsPhp($this->cli->path());

		$z = WordpressIntegrity::summarize(
			WordpressIntegrity::parseCore($core['code'], $core['out'], $core['err']),
			WordpressIntegrity::parsePlugins($plugins['code'], $plugins['out'], $plugins['err'], $this->cli->path()),
			$uploads
		);

		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_installs` SET
				`integrity_checked_at` = :time, `integrity_modified` = :modified, `integrity_added` = :added,
				`integrity_missing` = :missing, `integrity_uploads` = :uploads, `integrity_details` = :details
			WHERE `id` = :id
		");
		Database::pexecute($stmt, [
			'time' => time(),
			'modified' => $z['modified'],
			'added' => $z['added'],
			'missing' => $z['missing'],
			'uploads' => $z['uploads'],
			'details' => json_encode($z['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'id' => (int)$this->install['id'],
		]);

		$this->note(WordpressIntegrity::logLine($z));
		$kinds = [
			'uploads' => Lang::t('PHP in uploads'),
			'modified' => Lang::t('modified'),
			'added' => Lang::t('added'),
			'missing' => Lang::t('missing'),
		];
		foreach (array_slice($z['details']['paths'], 0, 20) as $entry) {
			$this->log[] = sprintf('  %-15s %s', $kinds[$entry['kind']] ?? $entry['kind'], $entry['path']);
		}
		if ($z['details']['paths_total'] > 20) {
			$this->log[] = '  ' . Lang::t('… and %d more', $z['details']['paths_total'] - 20);
		}
	}

	/**
	 * Database and files into a directory only root can access — a backup
	 * contains wp-config.php including the database password.
	 */
	private function backup(): void
	{
		$base = rtrim(trim((string)Settings::Get('ext_projectview.wp_backup_dir')) ?: '/var/backups/froxlor-project-view', '/');
		$this->checkBackupBase($base);

		$project = $base . '/' . (int)$this->install['domainid'];
		// Timestamp with milliseconds: two backups of the same installation in
		// the same second would otherwise get the same directory name and the
		// second would overwrite the first. The millisecond part keeps the
		// names unique and the lexicographic order chronological.
		$now = microtime(true);
		$stamp = date('Ymd-His', (int)$now) . sprintf('-%03d', (int)(($now - floor($now)) * 1000));
		$target = $project . '/' . (int)$this->install['id'] . '-' . $stamp;
		// Only the levels created here get 0700 — an existing $base (such as
		// /var/backups) is not switched.
		if (!is_dir($base)) {
			$this->rootDirectory($base);
		}
		$this->rootDirectory($project);
		$this->checkFreeSpace($project);
		$this->rootDirectory($target);
		$this->note(Lang::t('Backing up to %s', $target));

		// WP-CLI exports to stdout as the customer; gzip and the target file
		// are opened by the outer shell as root, in the root-owned directory.
		// --defaults: otherwise WP-CLI calls mysqldump with --no-defaults and
		// ignores the server's client settings (socket, SSL).
		// WP-CLI sets --no-tablespaces itself where mysqldump supports it.
		// The size of what root writes is capped to the free space minus the
		// reserve, so a huge or endless stream cannot fill the disk.
		$limit = $this->streamLimit($project);
		$db = $this->cli->wp(
			['db', 'export', '-', '--defaults', '--single-transaction'],
			$target . '/' . self::BACKUP_DATABASE_FILE,
			null,
			$limit
		);
		if ($db['limit_exceeded']) {
			$this->discardIncomplete($target);
			throw new RuntimeException(Lang::t('The backup exceeded the available space (%s) and was discarded — nothing was updated.', self::bytes($limit)));
		}
		if ($db['code'] !== 0) {
			if ($db['err'] !== '') {
				$this->log[] = $this->truncate($db['err']);
			}
			$this->discardIncomplete($target);
			throw new RuntimeException(Lang::t('The database backup failed — nothing was updated.'));
		}

		// tar runs as the customer and therefore reads only what the customer
		// may read anyway — if they redirect their installation directory to a
		// foreign place, access fails instead of root packing foreign data.
		// The archive goes to stdout; the target file in the root-owned
		// directory is opened by the outer shell. Uploads and caches are left
		// out: an update does not touch them, and they can be arbitrarily large.
		// It runs in the verified installation directory (see WordpressCli).
		$limit = $this->streamLimit($project);
		$tar = $this->cli->asCustomer(
			[
				'tar', '-czf', '-',
				'--exclude=./wp-content/uploads', '--exclude=./wp-content/cache', '--exclude=./wp-content/upgrade',
				'.',
			],
			null,
			$target . '/' . self::BACKUP_FILES_FILE,
			null,
			$limit
		);
		if ($tar['limit_exceeded']) {
			$this->discardIncomplete($target);
			throw new RuntimeException(Lang::t('The backup exceeded the available space (%s) and was discarded — nothing was updated.', self::bytes($limit)));
		}
		// Exit code 1 means for GNU tar: files changed while being read. That is
		// normal for a live website.
		if ($tar['code'] > 1) {
			$this->log[] = $this->truncate($tar['err']);
			$this->discardIncomplete($target);
			throw new RuntimeException(Lang::t('The file backup failed — nothing was updated.'));
		}

		$this->backupPath = $target;
		$this->note(Lang::t(
			'Backed up: database %s, files %s',
			self::size($target . '/' . self::BACKUP_DATABASE_FILE),
			self::size($target . '/' . self::BACKUP_FILES_FILE)
		));
		$this->removeOldBackups($project);
	}

	/**
	 * Refuses to start a backup without enough room: at least twice the size
	 * of what will be packed (the installation without uploads and caches,
	 * measured as the customer) plus the reserve. The database is not
	 * measured; its stream is capped like the file archive.
	 */
	private function checkFreeSpace(string $project): void
	{
		$free = @disk_free_space($project);
		if ($free === false) {
			throw new RuntimeException(Lang::t('Could not determine the free space in %s — nothing was updated.', $project));
		}
		$du = $this->cli->asCustomer(
			['du', '-sk', '--exclude=./wp-content/uploads', '--exclude=./wp-content/cache', '--exclude=./wp-content/upgrade', '.'],
			null,
			null,
			WordpressCli::QUERY_TIME_LIMIT
		);
		$size = $du['code'] === 0 ? (int)strtok($du['out'], " \t") * 1024 : 0;
		$needed = 2 * $size + self::BACKUP_RESERVE;
		if ($free < $needed) {
			throw new RuntimeException(Lang::t(
				'Not enough free space for the backup in %s: %s free, %s needed — nothing was updated.',
				$project,
				self::bytes((int)$free),
				self::bytes($needed)
			));
		}
	}

	/** The cap for the next backup stream: free space minus the reserve. */
	private function streamLimit(string $project): int
	{
		$free = @disk_free_space($project);
		$limit = $free === false ? 0 : (int)$free - self::BACKUP_RESERVE;
		if ($limit <= 0) {
			throw new RuntimeException(Lang::t('Could not determine the free space in %s — nothing was updated.', $project));
		}

		return $limit;
	}

	/**
	 * Checks the configured backup directory before root writes there.
	 *
	 * It is admin input, but every component of the path is checked with
	 * lstat: if it exists, it must be owned by root and must be neither a
	 * symbolic link nor writable by group or world. Otherwise a third party
	 * with write access — including a customer whose directory lies on the
	 * path — could redirect the chain and make root write into a foreign file.
	 */
	private function checkBackupBase(string $base): void
	{
		if ($base === '' || $base === '/' || !str_starts_with($base, '/') || str_contains($base, '..')) {
			throw new RuntimeException(Lang::t('No valid backup directory is set.'));
		}
		$customers = rtrim((string)Settings::Get('system.documentroot_prefix'), '/');
		if ($customers !== '' && ($base === $customers || str_starts_with($base . '/', $customers . '/'))) {
			throw new RuntimeException(Lang::t('The backup directory is inside the customer directories (%s) — please choose a location outside them in the settings.', $customers));
		}

		$parts = explode('/', ltrim($base, '/'));
		$path = '';
		foreach ($parts as $part) {
			$path .= '/' . $part;
			$info = @lstat($path);
			if ($info === false) {
				break; // from here on we create it ourselves
			}
			if (is_link($path)) {
				throw new RuntimeException(Lang::t('The backup path contains a symbolic link (%s) — aborted.', $path));
			}
			if ($info['uid'] !== 0) {
				throw new RuntimeException(Lang::t('The backup path %s is not owned by root — aborted.', $path));
			}
			if (($info['mode'] & 0022) !== 0) {
				throw new RuntimeException(Lang::t('The backup path %s is writable by group or world — aborted.', $path));
			}
		}
	}

	/**
	 * Creates a directory for root (0700) or checks that an existing one is
	 * owned by root and is not a link. An existing one is not changed —
	 * otherwise a backup path like /var would lock out other services.
	 */
	private function rootDirectory(string $path): void
	{
		if (is_link($path)) {
			throw new RuntimeException(Lang::t('The backup directory %s is a symbolic link — aborted.', $path));
		}
		if (is_dir($path)) {
			$info = @lstat($path);
			if ($info !== false && $info['uid'] !== 0) {
				throw new RuntimeException(Lang::t('The backup directory %s is not owned by root — aborted.', $path));
			}
			return;
		}
		if (!mkdir($path, 0700, true)) {
			throw new RuntimeException(Lang::t('Could not create the backup directory %s.', $path));
		}
	}

	/**
	 * Half a backup is useless for restoring — and would otherwise count
	 * towards retention and push out a good one.
	 */
	private function discardIncomplete(string $target): void
	{
		WordpressCli::asRoot(['rm', '-rf', '--', $target]);
		$this->note(Lang::t('Discarded the incomplete backup.'));
	}

	private function removeOldBackups(string $project): void
	{
		$keep = max(1, (int)(Settings::Get('ext_projectview.wp_backup_keep') ?: 3));
		$all = array_values(array_filter(
			glob($project . '/' . (int)$this->install['id'] . '-*', GLOB_ONLYDIR) ?: [],
			static fn($path) => (bool)preg_match('/^\d+-\d{8}-\d{6}(-\d{3})?$/', basename($path))
		));
		sort($all);
		foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
			WordpressCli::asRoot(['rm', '-rf', '--', $old]);
			$this->note(Lang::t('Removed old backup: %s', basename($old)));
		}
	}

	/**
	 * Installs the one-time login plugin with a fresh key pair. Setting it up
	 * again replaces the key — older links are worthless afterwards.
	 */
	private function installLogin(): void
	{
		$admins = json_decode((string)($this->install['admin_users'] ?? ''), true);
		if (!is_array($admins) || $admins === []) {
			$this->note(Lang::t('No administrators known yet — check first.'));
			$this->check();
		}

		[$secret, $public] = WordpressLogin::keyPair();
		// Relative to the verified installation directory (see WordpressCli).
		$directory = 'wp-content/mu-plugins';
		$file = $directory . '/' . WordpressLogin::FILE;

		// Everything as the customer: if they place a symbolic link here, they
		// only write to where they may write anyway.
		$this->asCustomer(['mkdir', '-p', '--', $directory]);
		$this->asCustomer(
			['sh', '-c', 'cat > "$1.new" && chmod 0644 "$1.new" && mv -f "$1.new" "$1"', 'sh', $file],
			WordpressLogin::muPlugin((int)$this->install['id'], $public)
		);

		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_installs` SET `login_secret_key` = :s, `login_installed` = 1 WHERE `id` = :id
		");
		Database::pexecute($stmt, ['s' => $secret, 'id' => (int)$this->install['id']]);
		$this->note(Lang::t('One-time login set up: %s', $this->cli->path() . '/' . $file));
	}

	private function removeLogin(): void
	{
		$this->asCustomer(['rm', '-f', '--', 'wp-content/mu-plugins/' . WordpressLogin::FILE]);
		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_installs` SET `login_secret_key` = NULL, `login_installed` = 0 WHERE `id` = :id
		");
		Database::pexecute($stmt, ['id' => (int)$this->install['id']]);
		$this->note(Lang::t('One-time login removed. Links already created are now worthless.'));
	}

	/**
	 * @param bool $required   an error aborts the job
	 * @param bool $showOutput the output belongs in the log (not for pure JSON queries)
	 * @param int|null $timeLimit seconds; null = WordpressCli::TIME_LIMIT
	 */
	private function wp(array $arguments, bool $required = true, bool $showOutput = true, ?int $timeLimit = null): array
	{
		$r = $this->cli->wp($arguments, null, $timeLimit);
		$this->note('$ wp ' . implode(' ', $arguments));
		if ($showOutput && $r['out'] !== '') {
			$this->log[] = $this->truncate($r['out']);
		}
		if ($r['err'] !== '' && ($showOutput || $r['code'] !== 0)) {
			$this->log[] = $this->truncate($r['err']);
		}
		if ($required && $r['code'] !== 0) {
			$command = implode(' ', array_slice($arguments, 0, 2));
			throw new RuntimeException(
				$r['code'] === 124
					? Lang::t('Time limit exceeded for “wp %s” (return value %d)', $command, $r['code'])
					: Lang::t('Failed: “wp %s” (return value %d)', $command, $r['code'])
			);
		}

		return $r;
	}

	private function jsonList(array $arguments): array
	{
		// Pure read-only queries: short time limit.
		$out = $this->wp(array_merge($arguments, ['--format=json']), true, false, WordpressCli::QUERY_TIME_LIMIT)['out'];
		// Without results some commands print a success message instead of "[]".
		if ($out === '' || str_starts_with($out, 'Success')) {
			return [];
		}
		$data = json_decode($out, true);
		if (!is_array($data)) {
			// PHP notices from WordPress occasionally land on stdout in front
			// of the JSON — then the last line counts.
			$lines = preg_split('/\R/', $out);
			$data = json_decode((string)end($lines), true);
		}
		if (!is_array($data)) {
			throw new RuntimeException(Lang::t('Unexpected response from “wp %s”.', implode(' ', $arguments)));
		}

		return $data;
	}

	private function asCustomer(array $command, ?string $input = null): void
	{
		$r = $this->cli->asCustomer($command, $input);
		if ($r['code'] !== 0) {
			$this->log[] = $this->truncate($r['err'] !== '' ? $r['err'] : $r['out']);
			throw new RuntimeException(Lang::t('Failed as customer: %s', $command[0]));
		}
	}

	private function note(string $text): void
	{
		$this->log[] = '[' . date('H:i:s') . '] ' . $text;
	}

	private function text(): string
	{
		return implode("\n", $this->log);
	}

	private function truncate(string $text): string
	{
		return strlen($text) > 4000 ? substr($text, 0, 4000) . "\n" . Lang::t('… (truncated)') : $text;
	}

	private static function size(string $file): string
	{
		$bytes = (int)@filesize($file);

		return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
	}

	private static function bytes(int $bytes): string
	{
		return ProjectStats::humanReadable(intdiv(max(0, $bytes), 1024));
	}
}
