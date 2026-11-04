<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use PDO;

/**
 * Disk usage per project: files plus databases.
 *
 * Computed once a day in the scan cron (as root), not during a page request —
 * `du` over customer directories needs root and can take a while. The project
 * overview and the detail page only read the stored value.
 *
 * Mail is deliberately left out: mailboxes belong to e-mail addresses, not to
 * the domain's file path, and their size cannot be attributed to a domain
 * reliably without access to the Maildir structure.
 */
class ProjectStats
{
	/** du gets at most this many seconds per project. */
	private const DU_TIME_LIMIT = 300;

	/** Cache for all(): one query per page request is enough. */
	private static ?array $cache = null;

	/**
	 * Recomputes the usage of all projects and stores it.
	 *
	 * @return int number of updated projects
	 */
	public static function computeAll(): int
	{
		$projects = self::projects();
		if ($projects === []) {
			return 0;
		}

		$files = [];
		foreach ($projects as $domainid => $docroot) {
			$files[$domainid] = self::directorySizeKb($docroot);
		}

		$databases = self::databaseSizesKb();

		$time = time();
		$stmt = Database::prepare("
			INSERT INTO `ext_projectview_stats` (`domainid`, `disk_files_kb`, `disk_db_kb`, `computed_at`)
			VALUES (:d, :f, :b, :z)
			ON DUPLICATE KEY UPDATE `disk_files_kb` = :f2, `disk_db_kb` = :b2, `computed_at` = :z2
		");
		foreach ($projects as $domainid => $docroot) {
			$f = $files[$domainid] ?? 0;
			$b = $databases[$domainid] ?? 0;
			Database::pexecute($stmt, [
				'd' => $domainid, 'f' => $f, 'b' => $b, 'z' => $time,
				'f2' => $f, 'b2' => $b, 'z2' => $time,
			]);
		}

		// Remove orphaned rows (domain deleted).
		Database::query("
			DELETE `s` FROM `ext_projectview_stats` `s`
			LEFT JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `s`.`domainid`
			WHERE `d`.`id` IS NULL
		");

		return count($projects);
	}

	/**
	 * Projects as in the scanner: main domains without the standard subdomain.
	 * Document roots behind a symbolic link below the customer directory are
	 * left out (same rule as WordpressScanner::scanAll()), so a customer
	 * cannot point the measurement at someone else's directory.
	 *
	 * @return array<int,string> domainid => documentroot
	 */
	private static function projects(): array
	{
		$stmt = Database::prepare("
			SELECT `d`.`id`, `d`.`documentroot`, `c`.`documentroot` AS `customer_docroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`parentdomainid` = '0' AND `d`.`documentroot` <> ''
			  AND (`c`.`standardsubdomain` IS NULL OR `c`.`standardsubdomain` <> `d`.`id`)
		");
		Database::pexecute($stmt);

		$projects = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$docroot = rtrim((string)$row['documentroot'], '/');
			$customerRoot = rtrim((string)($row['customer_docroot'] ?? ''), '/');
			$base = ($customerRoot !== '' && str_starts_with($docroot . '/', $customerRoot . '/'))
				? $customerRoot
				: dirname($docroot);
			$projects[(int)$row['id']] = WordpressScanner::pathHasNoSymlinks($base, $docroot) ? $docroot : '';
		}

		return $projects;
	}

	/**
	 * Size of a directory in KiB via `du`. du does not follow symbolic links
	 * (it counts the link itself, not its target) — so a customer cannot make
	 * root count foreign directories.
	 */
	private static function directorySizeKb(string $directory): int
	{
		if ($directory === '' || !is_dir($directory)) {
			return 0;
		}
		$output = [];
		$code = 0;
		// Time limit: a customer with millions of files must not stall the cron.
		@exec('timeout ' . self::DU_TIME_LIMIT . ' du -sk -- ' . escapeshellarg($directory) . ' 2>/dev/null', $output, $code);
		if ($code !== 0 || $output === []) {
			return 0;
		}

		return (int)strtok(trim((string)$output[0]), " \t");
	}

	/**
	 * Database sizes per project in KiB.
	 *
	 * The assigned databases come from the own mapping table; the sizes from
	 * information_schema, for which the root connection of the respective
	 * database server is used briefly. Afterwards back to the normal
	 * connection — otherwise the following writes would run as root.
	 *
	 * @return array<int,int> domainid => KiB
	 */
	private static function databaseSizesKb(): array
	{
		$stmt = Database::prepare("
			SELECT `m`.`domainid`, `db`.`databasename`, `db`.`dbserver`
			FROM `ext_projectview_resources` `m`
			INNER JOIN `" . TABLE_PANEL_DATABASES . "` `db` ON `db`.`id` = `m`.`resource_id`
			WHERE `m`.`resource_type` = 'database'
		");
		Database::pexecute($stmt);

		// Group by database server; every server has its own root connection.
		$byServer = [];
		$domainOfDb = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$server = (int)$row['dbserver'];
			$name = (string)$row['databasename'];
			$byServer[$server][$name] = true;
			$domainOfDb[$server][$name] = (int)$row['domainid'];
		}
		if ($byServer === []) {
			return [];
		}

		$result = [];
		try {
			foreach ($byServer as $server => $names) {
				$names = array_keys($names);
				Database::needRoot(true, $server, false);
				$placeholders = implode(', ', array_fill(0, count($names), '?'));
				$query = Database::prepare("
					SELECT `table_schema` AS `name`, SUM(`data_length` + `index_length`) AS `bytes`
					FROM `information_schema`.`TABLES`
					WHERE `table_schema` IN (" . $placeholders . ")
					GROUP BY `table_schema`
				");
				Database::pexecute($query, $names);
				while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
					$domainid = $domainOfDb[$server][(string)$row['name']] ?? null;
					if ($domainid !== null) {
						$result[$domainid] = ($result[$domainid] ?? 0) + (int)round((int)$row['bytes'] / 1024);
					}
				}
			}
		} finally {
			// In any case back to the normal Froxlor connection.
			Database::needRoot(false);
		}

		return $result;
	}

	/**
	 * Stored values per project, for the overview.
	 *
	 * @return array<int,array{files:int, db:int, total:int, computed_at:int}>
	 */
	public static function all(): array
	{
		if (self::$cache !== null) {
			return self::$cache;
		}
		$stmt = Database::query("SELECT * FROM `ext_projectview_stats`");
		$values = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$files = (int)$row['disk_files_kb'];
			$db = (int)$row['disk_db_kb'];
			$values[(int)$row['domainid']] = [
				'files' => $files,
				'db' => $db,
				'total' => $files + $db,
				'computed_at' => (int)$row['computed_at'],
			];
		}
		self::$cache = $values;

		return $values;
	}

	public static function forDomain(int $domainid): ?array
	{
		return self::all()[$domainid] ?? null;
	}

	/**
	 * KiB in human-readable form: 0 → "0 KB", otherwise KB/MB/GB.
	 */
	public static function humanReadable(int $kb): string
	{
		if ($kb <= 0) {
			return '0 KB';
		}
		$units = ['KB', 'MB', 'GB', 'TB'];
		$value = (float)$kb;
		$i = 0;
		while ($value >= 1024 && $i < count($units) - 1) {
			$value /= 1024;
			$i++;
		}

		return ($value >= 100 || $i === 0 ? (string)round($value) : number_format($value, 1)) . ' ' . $units[$i];
	}
}
