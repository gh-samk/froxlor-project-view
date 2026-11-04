<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\CurrentUser;
use Froxlor\Database\Database;
use Froxlor\Settings;

/**
 * Reads projects and their resources.
 *
 * A project is a main domain (panel_domains with parentdomainid = 0). There
 * is deliberately no project master table of its own: the identity already
 * exists in Froxlor, and every additional table would be one more place that
 * can drift apart.
 *
 * This class only reads. Every write goes through the Froxlor commands in
 * lib/Froxlor/Api/Commands/, so validation, quota checks, system tasks and
 * logging are never bypassed.
 */
class ProjectRepository
{
	/**
	 * Cache for the key figures of all visible projects. Filled once per
	 * request, so the overview list does not query per row.
	 */
	private static ?array $aggregates = null;

	/**
	 * Froxlor convention: an admin only sees their own domains unless they
	 * have customers_see_all. Modelled on Api/Commands/Domains.php.
	 */
	private static function adminScope(string $alias = 'd'): array
	{
		if (CurrentUser::getField('customers_see_all')) {
			return ['', []];
		}
		return [
			' AND `' . $alias . '`.`adminid` = :scope_adminid ',
			['scope_adminid' => CurrentUser::getField('adminid')],
		];
	}

	/**
	 * All projects the logged-in admin has access to.
	 *
	 * The customer's standard subdomain (loginname.server.tld) is hidden: it
	 * is technically a main domain, but not a project. Froxlor's own domain
	 * list does not show it as a regular entry either.
	 */
	public static function listProjects(): array
	{
		[$scopeSql, $scopeParams] = self::adminScope();

		$stmt = Database::prepare("
			SELECT `d`.`id`, `d`.`domain`, `d`.`domain_ace`, `d`.`documentroot`,
			       `d`.`letsencrypt`, `d`.`ssl_redirect`, `d`.`deactivated`,
			       `d`.`customerid`,
			       `c`.`loginname`, `c`.`name`, `c`.`firstname`, `c`.`company`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`parentdomainid` = '0'
			  AND (`c`.`standardsubdomain` IS NULL OR `c`.`standardsubdomain` <> `d`.`id`)
			" . $scopeSql . "
			ORDER BY `d`.`domain_ace` ASC
		");
		Database::pexecute($stmt, $scopeParams);

		$projects = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$row['customer_label'] = self::customerLabel($row);
			$projects[] = $row;
		}

		return $projects;
	}

	/**
	 * A single project. Returns null if the domain does not exist, is not a
	 * main domain, is the customer's standard subdomain (not a project, as in
	 * listProjects) or lies outside the admin's visibility — the cases are
	 * deliberately indistinguishable for the caller.
	 */
	public static function getProject(int $domainid): ?array
	{
		[$scopeSql, $scopeParams] = self::adminScope();

		$stmt = Database::prepare("
			SELECT `d`.*, `c`.`loginname`, `c`.`name`, `c`.`firstname`, `c`.`company`,
			       `c`.`email` AS `customer_email`, `c`.`customerid` AS `cid`,
			       `c`.`deactivated` AS `customer_deactivated`,
			       `c`.`documentroot` AS `customer_docroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`id` = :id AND `d`.`parentdomainid` = '0'
			  AND (`c`.`standardsubdomain` IS NULL OR `c`.`standardsubdomain` <> `d`.`id`)
			" . $scopeSql . "
		");
		$row = Database::pexecute_first($stmt, array_merge(['id' => $domainid], $scopeParams));

		if (!$row) {
			return null;
		}
		$row['customer_label'] = self::customerLabel($row);
		$row['is_redirect'] = self::isUrl((string)$row['documentroot']);
		$row['relative_path'] = self::relativePath((string)$row['documentroot'], (string)$row['customer_docroot']);

		// For editing in the box: the part after the customer directory,
		// without slashes at the edges. null means the path lies outside (or
		// is a redirect) and can only be changed in Froxlor's own form.
		$base = rtrim((string)$row['customer_docroot'], '/') . '/';
		$row['docroot_rest'] = (!$row['is_redirect'] && str_starts_with((string)$row['documentroot'], $base))
			? trim(substr((string)$row['documentroot'], strlen($base)), '/')
			: null;

		return $row;
	}

	public static function customerLabel(array $row): string
	{
		if (!empty($row['company'])) {
			return $row['company'];
		}
		$name = trim(($row['firstname'] ?? '') . ' ' . ($row['name'] ?? ''));

		return $name !== '' ? $name : ($row['loginname'] ?? '—');
	}

	/**
	 * Froxlor stores a redirect as a URL in the documentroot field.
	 */
	public static function isUrl(string $target): bool
	{
		return (bool)preg_match('#^https?://#i', $target);
	}

	/**
	 * Path relative to the customer directory — the way Froxlor expects it in
	 * its own forms. If the path lies outside, it stays absolute.
	 */
	public static function relativePath(string $path, string $base): string
	{
		if ($base === '' || self::isUrl($path)) {
			return $path;
		}
		$base = rtrim($base, '/') . '/';
		if (str_starts_with($path, $base)) {
			$rest = substr($path, strlen($base));
			return $rest === '' ? '/' : '/' . ltrim($rest, '/');
		}

		return $path;
	}

	/**
	 * Subdomains of the project, including SSL state and target.
	 */
	public static function getSubdomains(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `s`.`id`, `s`.`domain`, `s`.`domain_ace`, `s`.`documentroot`,
			       `s`.`letsencrypt`, `s`.`ssl_redirect`, `s`.`ssl_enabled`, `s`.`deactivated`,
			       `s`.`iswildcarddomain`, `s`.`wwwserveralias`,
			       `c`.`documentroot` AS `customer_docroot`
			FROM `" . TABLE_PANEL_DOMAINS . "` `s`
			LEFT JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `s`.`customerid`
			WHERE `s`.`parentdomainid` = :id
			ORDER BY `s`.`domain_ace` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['is_redirect'] = self::isUrl((string)$row['documentroot']);
			$row['relative_path'] = self::relativePath((string)$row['documentroot'], (string)$row['customer_docroot']);
		}
		unset($row);

		return $rows;
	}

	/**
	 * Email addresses of the project. Froxlor provides this assignment
	 * itself: mail_virtual carries a domainid.
	 *
	 * Forwarders keep their position in the destination list: Froxlor deletes
	 * a forwarder by exactly this position (EmailForwarders::delete,
	 * forwarderid). The address itself — the mailbox — is in the list too and
	 * is only filtered out for display, not renumbered.
	 */
	public static function getEmails(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `v`.`id`, `v`.`email`, `v`.`email_full`, `v`.`destination`, `v`.`popaccountid`,
			       `v`.`iscatchall`, `v`.`description`, `v`.`spam_tag_level`, `v`.`spam_kill_level`,
			       `v`.`rewrite_subject`, `v`.`bypass_spam`, `v`.`policy_greylist`,
			       `u`.`quota`, `u`.`postfix`, `u`.`mboxsize`
			FROM `" . TABLE_MAIL_VIRTUAL . "` `v`
			LEFT JOIN `" . TABLE_MAIL_USERS . "` `u` ON `u`.`id` = `v`.`popaccountid`
			WHERE `v`.`domainid` = :id
			ORDER BY `v`.`email_full` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$senders = self::sendersByAddress($domainid);

		foreach ($rows as &$row) {
			$row['has_account'] = ((int)$row['popaccountid'] > 0);
			$row['account_active'] = $row['has_account'] && strtoupper((string)$row['postfix']) === 'Y';

			$forwarders = [];
			foreach (explode(' ', (string)$row['destination']) as $index => $target) {
				$target = trim($target);
				if ($target === '' || $target === $row['email_full']) {
					continue;
				}
				$forwarders[] = ['index' => $index, 'target' => $target];
			}
			$row['forwarders'] = $forwarders;
			$row['senders'] = $senders[$row['email_full']] ?? [];
		}
		unset($row);

		return $rows;
	}

	/**
	 * Allowed sender aliases per address (mail_sender_aliases).
	 */
	private static function sendersByAddress(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `s`.`id`, `s`.`email`, `s`.`allowed_sender`
			FROM `" . TABLE_MAIL_SENDER_ALIAS . "` `s`
			INNER JOIN `" . TABLE_MAIL_VIRTUAL . "` `v` ON `v`.`email_full` = `s`.`email`
			WHERE `v`.`domainid` = :id
			ORDER BY `s`.`allowed_sender` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		$byAddress = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$byAddress[$row['email']][] = $row;
		}

		return $byAddress;
	}

	/**
	 * Databases of the project — exclusively via the extension's own mapping
	 * table, because panel_databases has no domainid. Only databases of the
	 * project's own customer count, whatever the mapping table says.
	 */
	public static function getDatabases(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `db`.`id`, `db`.`databasename`, `db`.`description`, `db`.`dbserver`
			FROM `ext_projectview_resources` `m`
			INNER JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `m`.`domainid`
			INNER JOIN `" . TABLE_PANEL_DATABASES . "` `db` ON `db`.`id` = `m`.`resource_id` AND `db`.`customerid` = `d`.`customerid`
			WHERE `m`.`domainid` = :id AND `m`.`resource_type` = 'database'
			ORDER BY `db`.`databasename` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * FTP accounts of the project — likewise only via the mapping table and
	 * only those of the project's own customer.
	 */
	public static function getFtps(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `f`.`id`, `f`.`username`, `f`.`homedir`, `f`.`description`, `f`.`login_enabled`
			FROM `ext_projectview_resources` `m`
			INNER JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `m`.`domainid`
			INNER JOIN `" . TABLE_FTP_USERS . "` `f` ON `f`.`id` = `m`.`resource_id` AND `f`.`customerid` = `d`.`customerid`
			WHERE `m`.`domainid` = :id AND `m`.`resource_type` = 'ftp'
			ORDER BY `f`.`username` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Detected WordPress installations with the outdated flag, the result of
	 * the last check and the most recent jobs.
	 *
	 * This method deliberately does not return the secret autologin key: the
	 * result ends up in the template. The key is only available through
	 * wordpressLoginKey(), immediately before a link is signed.
	 */
	public static function getWordpress(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `id`, `domainid`, `path`, `version`, `last_seen`, `siteurl`, `admin_users`,
			       `update_core`, `update_plugins`, `update_themes`, `update_details`, `checked_at`,
			       `login_installed`, `integrity_checked_at`, `integrity_modified`, `integrity_added`,
			       `integrity_missing`, `integrity_uploads`, `integrity_details`
			FROM `ext_projectview_wp_installs`
			WHERE `domainid` = :id
			ORDER BY `path` ASC
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		$latest = self::latestWordpressVersion();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['latest'] = $latest;
			// The site address comes from the customer's database: only a
			// strictly validated, rebuilt URL reaches the "Open wp-admin" link.
			$row['siteurl'] = WordpressLogin::normalizeSiteUrl($row['siteurl']);
			$row['outdated'] = self::isOutdated($row['version'], $latest);
			$row['admin_users'] = array_values(array_filter(
				(array)json_decode((string)$row['admin_users'], true),
				'is_string'
			));
			$row['update_details'] = (array)json_decode((string)$row['update_details'], true);
			$row['integrity_details'] = (array)json_decode((string)$row['integrity_details'], true);
			$row['integrity_findings'] = (int)$row['integrity_modified'] + (int)$row['integrity_added']
				+ (int)$row['integrity_missing'] + (int)$row['integrity_uploads'];
			$row['jobs'] = WordpressJobs::forInstall((int)$row['id'], 5);
			$row['busy'] = false;
			foreach ($row['jobs'] as $job) {
				if (in_array($job['status'], ['queued', 'running'], true)) {
					$row['busy'] = true;
				}
			}
		}
		unset($row);

		return $rows;
	}

	/**
	 * The secret autologin key of an installation — for signing only.
	 */
	public static function wordpressLoginKey(int $installId): ?string
	{
		$stmt = Database::prepare("
			SELECT `login_secret_key` FROM `ext_projectview_wp_installs`
			WHERE `id` = :id AND `login_installed` = 1
		");
		$row = Database::pexecute_first($stmt, ['id' => $installId]);

		return $row && !empty($row['login_secret_key']) ? (string)$row['login_secret_key'] : null;
	}

	/**
	 * All host names of the customer who owns the domain — main and
	 * subdomains in their ACE (punycode) and Unicode form.
	 *
	 * The autologin uses this to check that the siteurl reported by
	 * WordPress really points to a site of this customer and not to a host the
	 * customer set freely in their database.
	 *
	 * The panel's own names (system.hostname, system.froxloraliases) and
	 * everything below them are never allowed, although the customer's
	 * standard subdomain lives there: a page on such a host would receive the
	 * panel's session cookie.
	 */
	public static function customerHostsForDomain(int $domainid): array
	{
		$stmt = Database::prepare("
			SELECT `siblings`.`domain`, `siblings`.`domain_ace`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			INNER JOIN `" . TABLE_PANEL_DOMAINS . "` `siblings` ON `siblings`.`customerid` = `d`.`customerid`
			WHERE `d`.`id` = :id
		");
		Database::pexecute($stmt, ['id' => $domainid]);

		$hosts = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			foreach ([$row['domain'], $row['domain_ace']] as $host) {
				$host = strtolower(trim((string)$host));
				if ($host !== '') {
					$hosts[$host] = true;
				}
			}
		}

		$panel = [];
		foreach (array_merge([(string)Settings::Get('system.hostname')], explode(',', (string)Settings::Get('system.froxloraliases'))) as $name) {
			$name = strtolower(trim($name, " \t."));
			if ($name !== '') {
				$panel[] = $name;
			}
		}

		return array_values(array_filter(array_keys($hosts), static function (string $host) use ($panel): bool {
			foreach ($panel as $name) {
				if ($host === $name || str_ends_with($host, '.' . $name)) {
					return false;
				}
			}
			return true;
		}));
	}

	public static function latestWordpressVersion(): ?string
	{
		// Intermediate variable, because Database::pexecute_first takes the
		// statement by reference.
		$stmt = Database::prepare("SELECT `version` FROM `ext_projectview_wp_latest` WHERE `id` = 1");
		$row = Database::pexecute_first($stmt);

		return $row && !empty($row['version']) ? $row['version'] : null;
	}

	/**
	 * Without a known target version nothing is marked outdated — better no
	 * statement than a wrong one.
	 */
	public static function isOutdated(?string $version, ?string $latest): bool
	{
		if (empty($version) || empty($latest)) {
			return false;
		}

		return version_compare($version, $latest, '<');
	}

	/**
	 * Key figures of all visible projects in one go.
	 *
	 * Deliberately four separate aggregate queries instead of a join: several
	 * LEFT JOINs on detail tables multiply the rows and yield wrong COUNTs as
	 * soon as more than one detail table has entries.
	 */
	public static function aggregates(): array
	{
		if (self::$aggregates !== null) {
			return self::$aggregates;
		}

		$agg = [];
		$touch = static function (array &$agg, $domainid) {
			$domainid = (int)$domainid;
			if (!isset($agg[$domainid])) {
				$agg[$domainid] = [
					'subdomains' => 0,
					'emails' => 0,
					'databases' => 0,
					'ftps' => 0,
					'wp_version' => null,
					'wp_count' => 0,
					'wp_outdated' => false,
				];
			}
			return $domainid;
		};

		$queries = [
			'subdomains' => "SELECT `parentdomainid` AS `k`, COUNT(*) AS `n`
			                 FROM `" . TABLE_PANEL_DOMAINS . "`
			                 WHERE `parentdomainid` > 0 GROUP BY `parentdomainid`",
			'emails' => "SELECT `domainid` AS `k`, COUNT(*) AS `n`
			             FROM `" . TABLE_MAIL_VIRTUAL . "` GROUP BY `domainid`",
			'databases' => "SELECT `domainid` AS `k`, COUNT(*) AS `n`
			                FROM `ext_projectview_resources`
			                WHERE `resource_type` = 'database' GROUP BY `domainid`",
			'ftps' => "SELECT `domainid` AS `k`, COUNT(*) AS `n`
			           FROM `ext_projectview_resources`
			           WHERE `resource_type` = 'ftp' GROUP BY `domainid`",
		];

		foreach ($queries as $key => $sql) {
			$stmt = Database::prepare($sql);
			Database::pexecute($stmt);
			while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
				$id = $touch($agg, $row['k']);
				$agg[$id][$key] = (int)$row['n'];
			}
		}

		// WordPress: show the oldest version found per project, because that
		// determines the risk.
		$latest = self::latestWordpressVersion();
		$stmt = Database::prepare("
			SELECT `domainid`, `version` FROM `ext_projectview_wp_installs`
		");
		Database::pexecute($stmt);
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$id = $touch($agg, $row['domainid']);
			$agg[$id]['wp_count']++;
			$current = $agg[$id]['wp_version'];
			if ($current === null || version_compare((string)$row['version'], $current, '<')) {
				$agg[$id]['wp_version'] = $row['version'];
			}
			$agg[$id]['wp_outdated'] = self::isOutdated($agg[$id]['wp_version'], $latest);
		}

		self::$aggregates = $agg;

		return $agg;
	}

	public static function aggregateFor(int $domainid): array
	{
		$agg = self::aggregates();

		return $agg[$domainid] ?? [
			'subdomains' => 0,
			'emails' => 0,
			'databases' => 0,
			'ftps' => 0,
			'wp_version' => null,
			'wp_count' => 0,
			'wp_outdated' => false,
		];
	}
}
