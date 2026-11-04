<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\CurrentUser;
use Froxlor\Database\Database;

/**
 * Assignment of databases and FTP accounts to projects.
 *
 * Background: panel_databases and ftp_users only carry a customerid, no
 * domainid. Froxlor therefore cannot assign these resources to a website at
 * all. Hence the own table ext_projectview_resources.
 *
 * Principle: suggestions are never written automatically. A wrongly guessed
 * assignment goes unnoticed once it is in the database — so a human always
 * confirms it.
 */
class ResourceMapper
{
	public const TYPE_DATABASE = 'database';
	public const TYPE_FTP = 'ftp';

	public static function isValidType(string $type): bool
	{
		return in_array($type, [self::TYPE_DATABASE, self::TYPE_FTP], true);
	}

	/**
	 * Assigns a resource to a project.
	 *
	 * The UNIQUE key on (resource_type, resource_id) enforces that a resource
	 * belongs to at most one project; assigning it again moves it.
	 */
	public static function assign(string $type, int $resourceId, int $domainid): void
	{
		if (!self::isValidType($type)) {
			throw new \InvalidArgumentException(Lang::t('Unknown resource type: %s', $type));
		}

		$stmt = Database::prepare("
			INSERT INTO `ext_projectview_resources`
				(`domainid`, `resource_type`, `resource_id`, `assigned_at`)
			VALUES (:domainid, :type, :rid, :ts)
			ON DUPLICATE KEY UPDATE `domainid` = VALUES(`domainid`), `assigned_at` = VALUES(`assigned_at`)
		");
		Database::pexecute($stmt, [
			'domainid' => $domainid,
			'type' => $type,
			'rid' => $resourceId,
			'ts' => time(),
		]);
	}

	/**
	 * Owner (customerid) of a database or FTP account, or null if it does not
	 * exist. Used to check that a resource may be assigned to a project at all:
	 * only to a project of the same customer.
	 */
	public static function resourceCustomer(string $type, int $resourceId): ?int
	{
		if (!self::isValidType($type)) {
			return null;
		}
		$table = $type === self::TYPE_DATABASE ? TABLE_PANEL_DATABASES : TABLE_FTP_USERS;
		$stmt = Database::prepare("SELECT `customerid` FROM `" . $table . "` WHERE `id` = :id");
		$row = Database::pexecute_first($stmt, ['id' => $resourceId]);

		return $row ? (int)$row['customerid'] : null;
	}

	/**
	 * Project (domain id) a resource is currently assigned to, or null.
	 */
	public static function assignedProject(string $type, int $resourceId): ?int
	{
		$stmt = Database::prepare("
			SELECT `domainid` FROM `ext_projectview_resources`
			WHERE `resource_type` = :type AND `resource_id` = :rid
		");
		$row = Database::pexecute_first($stmt, ['type' => $type, 'rid' => $resourceId]);

		return $row ? (int)$row['domainid'] : null;
	}

	/**
	 * Removes an assignment.
	 *
	 * $domainid is mandatory and part of the condition: the calling page has
	 * already checked this project against the admin's visibility. Without
	 * this binding a manipulated form could remove the assignment of a foreign
	 * project — Froxlor does not protect our own table.
	 */
	public static function unassign(string $type, int $resourceId, int $domainid): void
	{
		if (!self::isValidType($type)) {
			throw new \InvalidArgumentException(Lang::t('Unknown resource type: %s', $type));
		}

		$stmt = Database::prepare("
			DELETE FROM `ext_projectview_resources`
			WHERE `resource_type` = :type AND `resource_id` = :rid AND `domainid` = :domainid
		");
		Database::pexecute($stmt, [
			'type' => $type,
			'rid' => $resourceId,
			'domainid' => $domainid,
		]);
	}

	/**
	 * Cleanup: mappings to resources or projects that no longer exist.
	 * Froxlor does not know our table and deletes nothing there when a
	 * database is removed.
	 */
	public static function pruneOrphans(): int
	{
		$removed = 0;

		$stmt = Database::prepare("
			DELETE `m` FROM `ext_projectview_resources` `m`
			LEFT JOIN `" . TABLE_PANEL_DATABASES . "` `db` ON `db`.`id` = `m`.`resource_id`
			WHERE `m`.`resource_type` = 'database' AND `db`.`id` IS NULL
		");
		Database::pexecute($stmt);
		$removed += $stmt->rowCount();

		$stmt = Database::prepare("
			DELETE `m` FROM `ext_projectview_resources` `m`
			LEFT JOIN `" . TABLE_FTP_USERS . "` `f` ON `f`.`id` = `m`.`resource_id`
			WHERE `m`.`resource_type` = 'ftp' AND `f`.`id` IS NULL
		");
		Database::pexecute($stmt);
		$removed += $stmt->rowCount();

		$stmt = Database::prepare("
			DELETE `m` FROM `ext_projectview_resources` `m`
			LEFT JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `m`.`domainid`
			WHERE `d`.`id` IS NULL
		");
		Database::pexecute($stmt);
		$removed += $stmt->rowCount();

		return $removed;
	}

	/**
	 * Plain number of resources not assigned yet.
	 *
	 * Separate from the lists because the overview only needs a number for the
	 * badge and computing suggestions for it would be needlessly expensive.
	 */
	public static function countUnassigned(): int
	{
		return self::countUnassignedOf(self::TYPE_DATABASE) + self::countUnassignedOf(self::TYPE_FTP);
	}

	private static function countUnassignedOf(string $type): int
	{
		[$scopeSql, $scopeParams] = self::customerScope('c');

		$table = $type === self::TYPE_DATABASE ? TABLE_PANEL_DATABASES : TABLE_FTP_USERS;

		$stmt = Database::prepare("
			SELECT COUNT(*) AS `n`
			FROM `" . $table . "` `r`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `r`.`customerid`
			LEFT JOIN `ext_projectview_resources` `m`
			       ON `m`.`resource_id` = `r`.`id` AND `m`.`resource_type` = :type
			WHERE `m`.`id` IS NULL " . $scopeSql . "
		");
		$row = Database::pexecute_first($stmt, array_merge(['type' => $type], $scopeParams));

		return (int)($row['n'] ?? 0);
	}

	/**
	 * Databases not assigned yet, including a suggestion.
	 *
	 * This section stays permanently. There will always be resources without
	 * a project — test databases, shared accounts, leftovers. A system that
	 * pretends they do not exist loses trust exactly there.
	 */
	public static function unassignedDatabases(): array
	{
		[$scopeSql, $scopeParams] = self::customerScope('c');

		$stmt = Database::prepare("
			SELECT `db`.`id`, `db`.`databasename`, `db`.`description`, `db`.`customerid`,
			       `c`.`loginname`, `c`.`name`, `c`.`firstname`, `c`.`company`
			FROM `" . TABLE_PANEL_DATABASES . "` `db`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `db`.`customerid`
			LEFT JOIN `ext_projectview_resources` `m`
			       ON `m`.`resource_id` = `db`.`id` AND `m`.`resource_type` = 'database'
			WHERE `m`.`id` IS NULL " . $scopeSql . "
			ORDER BY `db`.`databasename` ASC
		");
		Database::pexecute($stmt, $scopeParams);

		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['customer_label'] = ProjectRepository::customerLabel($row);
			$row['suggestions'] = self::suggestByName(
				(string)$row['databasename'],
				(int)$row['customerid']
			);
			$row['domains'] = self::mainDomainsOfCustomer((int)$row['customerid']);
		}
		unset($row);

		return $rows;
	}

	/**
	 * FTP accounts not assigned yet, including a suggestion.
	 */
	public static function unassignedFtps(): array
	{
		[$scopeSql, $scopeParams] = self::customerScope('c');

		$stmt = Database::prepare("
			SELECT `f`.`id`, `f`.`username`, `f`.`homedir`, `f`.`description`, `f`.`customerid`,
			       `c`.`loginname`, `c`.`name`, `c`.`firstname`, `c`.`company`
			FROM `" . TABLE_FTP_USERS . "` `f`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `f`.`customerid`
			LEFT JOIN `ext_projectview_resources` `m`
			       ON `m`.`resource_id` = `f`.`id` AND `m`.`resource_type` = 'ftp'
			WHERE `m`.`id` IS NULL " . $scopeSql . "
			ORDER BY `f`.`username` ASC
		");
		Database::pexecute($stmt, $scopeParams);

		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['customer_label'] = ProjectRepository::customerLabel($row);
			$row['suggestions'] = self::suggestByPath(
				(string)$row['homedir'],
				(int)$row['customerid']
			);
			$row['domains'] = self::mainDomainsOfCustomer((int)$row['customerid']);
		}
		unset($row);

		return $rows;
	}

	/**
	 * FTP suggestion by path: if homedir lies inside the documentroot of a
	 * main domain of the same customer, the account most likely belongs to
	 * that project.
	 *
	 * No automatism: homedir is a freely chosen path (Api/Commands/Ftps.php),
	 * so the match is an indication, not proof. The longest matching
	 * documentroot wins, so nested projects go to the more specific one.
	 */
	public static function suggestByPath(string $homedir, int $customerid): array
	{
		$homedir = self::normalizePath($homedir);
		if ($homedir === '') {
			return [];
		}

		$candidates = static::mainDomainsOfCustomer($customerid);
		$matches = [];
		foreach ($candidates as $domain) {
			$docroot = self::normalizePath((string)$domain['documentroot']);
			if ($docroot === '' || $docroot === '/') {
				continue;
			}
			if ($homedir === $docroot || str_starts_with($homedir, $docroot)) {
				$domain['match_length'] = strlen($docroot);
				$matches[] = $domain;
			}
		}

		usort($matches, static fn($a, $b) => $b['match_length'] <=> $a['match_length']);

		return array_slice($matches, 0, 3);
	}

	/**
	 * Database suggestion by name similarity.
	 *
	 * Databases offer no structural clue at all. The name is the only thing
	 * left — the suggestion is correspondingly weak, and the interface must
	 * mark it clearly as such.
	 */
	public static function suggestByName(string $databasename, int $customerid): array
	{
		$needle = strtolower($databasename);
		if ($needle === '') {
			return [];
		}

		$matches = [];
		foreach (static::mainDomainsOfCustomer($customerid) as $domain) {
			$label = self::domainLabel((string)$domain['domain']);
			if ($label === '' || strlen($label) < 3) {
				continue;
			}
			if (str_contains($needle, $label)) {
				$domain['match_length'] = strlen($label);
				$matches[] = $domain;
			}
		}

		usort($matches, static fn($a, $b) => $b['match_length'] <=> $a['match_length']);

		return array_slice($matches, 0, 3);
	}

	/**
	 * "customer-shop.example" -> "customershop"; separators are dropped
	 * because Froxlor does not allow them in database names anyway.
	 */
	private static function domainLabel(string $domain): string
	{
		$label = strtolower(explode('.', $domain)[0] ?? '');

		return preg_replace('/[^a-z0-9]/', '', $label) ?? '';
	}

	private static function normalizePath(string $path): string
	{
		$path = trim($path);
		if ($path === '') {
			return '';
		}

		return rtrim($path, '/') . '/';
	}

	/**
	 * Candidate domains of a customer.
	 *
	 * Called by the suggestion logic via static:: so that it can be tested
	 * without a database — the suggestion is the place where a bug would
	 * produce plausible wrong assignments.
	 */
	public static function mainDomainsOfCustomer(int $customerid): array
	{
		static $cache = [];
		if (isset($cache[$customerid])) {
			return $cache[$customerid];
		}

		// Same project definition as ProjectRepository::listProjects() and
		// WordpressScanner::scanAll(): the customer's standard subdomain is not
		// a project. Without this exclusion it would be offered as an
		// assignment target — and because its name starts with the login name,
		// it would even be the preselected suggestion for databases.
		$stmt = Database::prepare("
			SELECT `d`.`id`, `d`.`domain`, `d`.`domain_ace`, `d`.`documentroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`customerid` = :cid AND `d`.`parentdomainid` = '0'
			  AND (`c`.`standardsubdomain` IS NULL OR `c`.`standardsubdomain` <> `d`.`id`)
			ORDER BY `d`.`domain_ace` ASC
		");
		Database::pexecute($stmt, ['cid' => $customerid]);
		$cache[$customerid] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return $cache[$customerid];
	}

	/**
	 * Visibility boundary between admins, analogous to ProjectRepository.
	 */
	private static function customerScope(string $alias): array
	{
		if (CurrentUser::getField('customers_see_all')) {
			return ['', []];
		}

		return [
			' AND `' . $alias . '`.`adminid` = :scope_adminid ',
			['scope_adminid' => CurrentUser::getField('adminid')],
		];
	}
}
