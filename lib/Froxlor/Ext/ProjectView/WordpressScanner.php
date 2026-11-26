<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use PDO;

/**
 * Detects WordPress installations in the file system.
 *
 * Froxlor does not know WordPress — the only hit in its entire source is a
 * help text for an nginx setting. This class is therefore entirely custom.
 *
 * Security principle: strictly read-only. The cron runs as root over
 * customer directories. A customer file is never included or executed; the
 * version is read from the file content with a regular expression. An
 * include of wp-includes/version.php would be code execution from a foreign
 * directory and thus a direct attack path.
 *
 * No symbolic link below the customer directory is followed: neither on the
 * way to the document root, nor into an installation, nor to version.php.
 * Otherwise a customer could point the root-run scan at another customer's
 * files or at special files (a FIFO or a kernel pipe would block the read
 * forever). version.php is opened non-blocking and must be the same regular
 * file that lstat saw.
 */
class WordpressScanner
{
	/** How deep below the documentroot to search. */
	private const MAX_DEPTH = 2;

	/** Directories that are never searched. */
	private const SKIP_DIRS = [
		'wp-admin', 'wp-includes', 'wp-content', 'node_modules', 'vendor',
		'.git', '.svn', 'cache', 'logs', 'tmp', 'backup', 'backups',
	];

	private const VERSION_URL = 'https://api.wordpress.org/core/version-check/1.7/';

	/** Fetch the target version at most once per day. */
	private const VERSION_TTL = 86400;

	/** At most this many installations are recorded per project. */
	public const MAX_INSTALLS_PER_PROJECT = 20;

	/** At most this much of version.php is read. */
	private const VERSION_READ_MAX = 8192;

	/**
	 * Full run: scan all main domains, write the result, remove installations
	 * that have disappeared.
	 *
	 * @return array{scanned:int, found:int, removed:int, capped:int, skipped:int}
	 *         capped: projects with more than MAX_INSTALLS_PER_PROJECT installations;
	 *         skipped: projects whose document root lies behind a symbolic link
	 */
	public static function scanAll(): array
	{
		$runStarted = time();

		// Same project definition as ProjectRepository::listProjects(): the
		// customer's standard subdomain is not a project. Without this
		// exclusion it would collect everything twice, because its document
		// root is the parent directory of all other projects.
		$stmt = Database::prepare("
			SELECT `d`.`id`, `d`.`documentroot`, `c`.`documentroot` AS `customer_docroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`parentdomainid` = '0' AND `d`.`documentroot` <> ''
			  AND (`c`.`standardsubdomain` IS NULL OR `c`.`standardsubdomain` <> `d`.`id`)
			  AND " . ProjectRepository::NOT_MAIL_PACKAGE . "
		");
		Database::pexecute($stmt);

		$domains = [];
		$skipped = 0;
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$docroot = rtrim((string)$row['documentroot'], '/');
			if (!str_starts_with($docroot, '/')) {
				continue; // a redirect URL, no directory
			}
			// The customer directory is created by Froxlor below a root-owned
			// directory, so it is the trusted anchor; everything below it is
			// customer territory. A document root set elsewhere by an admin is
			// trusted up to its parent directory.
			$customerRoot = rtrim((string)($row['customer_docroot'] ?? ''), '/');
			$base = ($customerRoot !== '' && str_starts_with($docroot . '/', $customerRoot . '/'))
				? $customerRoot
				: dirname($docroot);
			if (!self::pathHasNoSymlinks($base, $docroot)) {
				$skipped++;
				continue;
			}
			$domains[] = ['id' => (int)$row['id'], 'docroot' => $docroot . '/'];
		}

		// Subdomains with their own directory are additional search roots; finds
		// are booked on their main domain. Redirects (URL instead of path) and
		// subdomains of excluded domains (standard subdomain, mail package) drop
		// out because their main domain is not in $domains.
		$projects = array_column($domains, 'id', 'id');
		$stmt = Database::prepare("
			SELECT `d`.`parentdomainid`, `d`.`documentroot`, `c`.`documentroot` AS `customer_docroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`parentdomainid` <> '0' AND `d`.`documentroot` LIKE '/%'
			ORDER BY `d`.`id` ASC
		");
		Database::pexecute($stmt);
		$candidates = $domains;
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if (!isset($projects[(int)$row['parentdomainid']])) {
				continue;
			}
			$docroot = rtrim((string)$row['documentroot'], '/');
			$customerRoot = rtrim((string)($row['customer_docroot'] ?? ''), '/');
			$base = ($customerRoot !== '' && str_starts_with($docroot . '/', $customerRoot . '/'))
				? $customerRoot
				: dirname($docroot);
			if (!self::pathHasNoSymlinks($base, $docroot)) {
				$skipped++;
				continue;
			}
			$candidates[] = ['id' => (int)$row['parentdomainid'], 'docroot' => $docroot . '/'];
		}

		$found = [];
		$capped = 0;
		foreach ($candidates as $candidate) {
			$installs = self::findInstalls($candidate['docroot']);
			if (count($installs) > self::MAX_INSTALLS_PER_PROJECT) {
				// Bounded work and table size per search root, however many copies
				// a customer creates; the first ones by path are kept.
				ksort($installs);
				$installs = array_slice($installs, 0, self::MAX_INSTALLS_PER_PROJECT, true);
				$capped++;
			}
			$found += $installs;
		}
		// The column path holds 255 characters: longer paths cannot be stored and
		// would never match a pin when truncated (the pin would be lost silently).
		$found = array_filter($found, static fn($v, $path) => strlen($path) <= 255, ARRAY_FILTER_USE_BOTH);

		$pins = [];
		$stmt = Database::prepare("
			SELECT `i`.`path`, `i`.`domainid`, `c`.`documentroot` AS `root`
			FROM `ext_projectview_wp_installs` `i`
			JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `i`.`domainid`
			JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `i`.`manual` = 1
		");
		Database::pexecute($stmt);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$pins[$row['path']] = ['id' => (int)$row['domainid'], 'root' => (string)$row['root']];
		}

		$best = self::assign($candidates, $found, $pins);
		foreach ($best as $path => $match) {
			self::record($match['id'], $path, $match['version'], $runStarted, $match['manual']);
		}

		return [
			'scanned' => count($domains),
			'found' => count($best),
			'removed' => self::forgetStale($runStarted),
			'capped' => $capped,
			'skipped' => $skipped,
		];
	}

	/**
	 * Assigns each find to one project: the candidate with the longest matching
	 * document root, on a tie the one named first. Manually assigned paths keep
	 * their project; the automatic assignment never applies to them.
	 *
	 * @param array<int,array{id:int,docroot:string}> $candidates docroot with trailing slash, id = project
	 * @param array<string,string|null> $found path => version
	 * @param array<string,array{id:int,root:string}> $pins path => project and customer directory
	 * @return array<string,array{id:int,version:?string,manual:bool}>
	 */
	public static function assign(array $candidates, array $found, array $pins): array
	{
		$best = [];
		$ids = array_column($candidates, 'id', 'id');
		foreach ($found as $path => $version) {
			// A pin only counts for an existing project whose customer directory
			// contains the path; otherwise the automatic assignment applies (and
			// record() releases the pin).
			$pin = $pins[$path] ?? null;
			$root = $pin ? rtrim($pin['root'], '/') . '/' : '';
			if ($pin && isset($ids[$pin['id']]) && $root !== '/' && str_starts_with(rtrim($path, '/') . '/', $root)) {
				$best[$path] = ['id' => $pin['id'], 'version' => $version, 'manual' => true];
				continue;
			}
			$normalized = rtrim($path, '/') . '/';
			$depth = -1;
			foreach ($candidates as $candidate) {
				$length = strlen($candidate['docroot']);
				if ($length > $depth && str_starts_with($normalized, $candidate['docroot'])) {
					$depth = $length;
					$best[$path] = ['id' => $candidate['id'], 'version' => $version, 'manual' => false];
				}
			}
		}

		return $best;
	}

	/**
	 * true if $path lies below (or is) $base and no component of $path below
	 * $base is a symbolic link; every component must exist. $base itself is
	 * trusted and not checked.
	 */
	public static function pathHasNoSymlinks(string $base, string $path): bool
	{
		$base = rtrim($base, '/');
		$path = rtrim($path, '/');
		if ($path === $base) {
			return $base !== '';
		}
		if (!str_starts_with($path, $base . '/')) {
			return false;
		}
		$current = $base;
		foreach (explode('/', substr($path, strlen($base) + 1)) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return false;
			}
			$current .= '/' . $part;
			clearstatcache(true, $current);
			if (@lstat($current) === false || is_link($current)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Searches below $root for wp-config.php.
	 *
	 * @return array<string,string|null> path => version (null if not readable)
	 */
	public static function findInstalls(string $root, int $depth = 0): array
	{
		$root = rtrim($root, '/');
		if ($root === '' || !is_dir($root) || !is_readable($root)) {
			return [];
		}
		// Do not follow symlinks: otherwise the scan would run across directory
		// boundaries or into a loop.
		if (is_link($root)) {
			return [];
		}

		$installs = [];

		if (is_file($root . '/wp-config.php')) {
			$installs[$root] = self::readVersion($root);
			// Do not descend further: subdirectories of an installation are not
			// installations of their own.
			return $installs;
		}

		if ($depth >= self::MAX_DEPTH) {
			return $installs;
		}

		$entries = @scandir($root);
		if ($entries === false) {
			return $installs;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
				continue;
			}
			if (in_array(strtolower($entry), self::SKIP_DIRS, true)) {
				continue;
			}
			$child = $root . '/' . $entry;
			if (!is_dir($child)) {
				continue;
			}
			$installs += self::findInstalls($child, $depth + 1);
		}

		return $installs;
	}

	/**
	 * Reads $wp_version from wp-includes/version.php — with a regex, without
	 * including the file. Only the beginning of the file is read; the
	 * assignment sits near the top in every WordPress version.
	 *
	 * Neither wp-includes nor version.php may be a symbolic link, and
	 * version.php must be a regular file. It is opened non-blocking ("n"), so
	 * a FIFO swapped in after the check cannot block the cron, and the opened
	 * file must be the very file lstat saw (same device and inode).
	 */
	public static function readVersion(string $installPath): ?string
	{
		$directory = rtrim($installPath, '/') . '/wp-includes';
		$versionFile = $directory . '/version.php';
		clearstatcache();
		$dirInfo = @lstat($directory);
		if ($dirInfo === false || ($dirInfo['mode'] & 0170000) !== 0040000) {
			return null;
		}
		$info = @lstat($versionFile);
		if ($info === false || ($info['mode'] & 0170000) !== 0100000) {
			return null;
		}

		$handle = @fopen($versionFile, 'rn');
		if ($handle === false) {
			return null;
		}
		$opened = fstat($handle);
		if ($opened === false || ($opened['mode'] & 0170000) !== 0100000
			|| $opened['ino'] !== $info['ino'] || $opened['dev'] !== $info['dev']) {
			fclose($handle);
			return null;
		}
		stream_set_blocking($handle, false);
		$content = fread($handle, self::VERSION_READ_MAX);
		fclose($handle);
		if ($content === false) {
			return null;
		}

		if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
			return substr(trim($m[1]), 0, 32);
		}

		return null;
	}

	private static function record(int $domainid, string $path, ?string $version, int $seenAt, bool $pin): void
	{
		// An existing row with this path (also under another project) is moved
		// instead of created anew: autologin, jobs and check data hang on its id.
		// rowCount() is unreliable for UPDATE, hence the lookup first.
		$stmt = Database::prepare("SELECT `id`, `domainid` FROM `ext_projectview_wp_installs` WHERE BINARY `path` = :path");
		Database::pexecute($stmt, ['path' => $path]);
		$existing = null;
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if ($existing === null || (int)$row['domainid'] === $domainid) {
				$existing = $row;
			}
		}

		if ($existing === null) {
			$stmt = Database::prepare("
				INSERT INTO `ext_projectview_wp_installs` (`domainid`, `path`, `version`, `last_seen`)
				VALUES (:domainid, :path, :version, :seen)
				ON DUPLICATE KEY UPDATE `version` = VALUES(`version`), `last_seen` = VALUES(`last_seen`)
			");
			Database::pexecute($stmt, ['domainid' => $domainid, 'path' => $path, 'version' => $version, 'seen' => $seenAt]);
			return;
		}

		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_installs`
			SET `domainid` = :domainid, `version` = :version, `last_seen` = :seen, `manual` = :pin WHERE `id` = :id
		");
		Database::pexecute($stmt, ['domainid' => $domainid, 'version' => $version, 'seen' => $seenAt, 'pin' => $pin ? 1 : 0, 'id' => (int)$existing['id']]);
	}

	/**
	 * Removes installations this run did not see anymore.
	 */
	private static function forgetStale(int $runStarted): int
	{
		$stmt = Database::prepare("
			DELETE FROM `ext_projectview_wp_installs` WHERE `last_seen` < :started
		");
		Database::pexecute($stmt, ['started' => $runStarted]);

		return $stmt->rowCount();
	}

	/**
	 * Fetches the current WordPress version from api.wordpress.org.
	 *
	 * If fetching fails, the last known value stays and the scan carries on
	 * normally — only the outdated flag is missing then. A scan that fails
	 * because of a missing internet connection would be much worse than one
	 * without a target version.
	 */
	public static function refreshLatestVersion(bool $force = false): ?string
	{
		// Intermediate variable, because Database::pexecute_first takes the
		// statement by reference.
		$stmt = Database::prepare("SELECT `version`, `fetched_at` FROM `ext_projectview_wp_latest` WHERE `id` = 1");
		$row = Database::pexecute_first($stmt);
		$known = $row['version'] ?? null;

		if (!$force && $row && (time() - (int)$row['fetched_at']) < self::VERSION_TTL) {
			return $known;
		}

		$latest = self::fetchLatestVersion();
		if ($latest === null) {
			return $known;
		}

		$upd = Database::prepare("
			UPDATE `ext_projectview_wp_latest` SET `version` = :v, `fetched_at` = :t WHERE `id` = 1
		");
		Database::pexecute($upd, ['v' => $latest, 't' => time()]);

		return $latest;
	}

	private static function fetchLatestVersion(): ?string
	{
		$context = stream_context_create([
			'http' => [
				'timeout' => 10,
				'method' => 'GET',
				'user_agent' => 'froxlor-project-view',
			],
		]);

		$json = @file_get_contents(self::VERSION_URL, false, $context);
		if ($json === false) {
			return null;
		}

		$data = json_decode($json, true);
		$current = $data['offers'][0]['current'] ?? null;

		return is_string($current) && $current !== '' ? substr($current, 0, 32) : null;
	}
}
