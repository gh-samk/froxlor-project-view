<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Api\Commands\SubDomains;
use Froxlor\Cron\TaskId;
use Froxlor\Database\Database;
use Froxlor\Froxlor;
use Froxlor\Settings;
use Froxlor\System\Cronjob;
use PDO;

/**
 * Mail package: mail., webmail., autoconfig. and autodiscover. for every
 * e-mail domain.
 *
 * All four are ordinary Froxlor subdomains of the customer with their own
 * Let's Encrypt certificate. Froxlor issues, renews and writes the vhosts;
 * the extension only creates them and, for the three web services, appends
 * its Apache lines to the subdomain's special settings.
 *
 * - mail.         Name for IMAP, POP3 and SMTP. The certificate reaches
 *                 Postfix and Dovecot via SNI (MailSni). In a browser: redirect to webmail.
 * - webmail.      Roundcube from the server's shared installation.
 * - autoconfig.   Thunderbird finds server and ports here.
 * - autodiscover. The same for Outlook.
 *
 * Every subdomain is created once. If the customer deletes one, it stays
 * gone; Project View shows that. Whatever already exists under the name is
 * never touched.
 */
class MailPackage
{
	public const KINDS = [
		'mail' => 'IMAP, POP3 and SMTP',
		'webmail' => 'Roundcube',
		'autoconfig' => 'Thunderbird setup',
		'autodiscover' => 'Outlook setup',
	];

	/** These run via PHP-FPM and need special settings. */
	public const WITH_PHP = ['webmail', 'autoconfig', 'autodiscover'];

	public const STATUS = [
		'created' => 'Created',
		'existing' => 'Already existed',
		'planned' => 'Would be created (dry run)',
		'waiting_dns' => 'Waiting for DNS',
		'skipped' => 'Skipped',
		'deleted' => 'Deleted by the customer',
		'failed' => 'Error',
	];

	/** The purposes of the four names in the current language (KINDS holds the English source texts). */
	public static function kindLabels(): array
	{
		return [
			'mail' => Lang::t('IMAP, POP3 and SMTP'),
			'webmail' => Lang::t('Roundcube'),
			'autoconfig' => Lang::t('Thunderbird setup'),
			'autodiscover' => Lang::t('Outlook setup'),
		];
	}

	/** The status labels in the current language (STATUS holds the English source texts). */
	public static function statusLabels(): array
	{
		return [
			'created' => Lang::t('Created'),
			'existing' => Lang::t('Already existed'),
			'planned' => Lang::t('Would be created (dry run)'),
			'waiting_dns' => Lang::t('Waiting for DNS'),
			'skipped' => Lang::t('Skipped'),
			'deleted' => Lang::t('Deleted by the customer'),
			'failed' => Lang::t('Error'),
		];
	}

	/** Waiting and failed entries are retried this often. */
	public const RETRY = 3600;

	/** Marker line in the special settings. */
	public const MARKER = '# Froxlor Project View mail package';

	/**
	 * What to do for one kind of a domain.
	 *
	 * @param array|null $entry row from ext_projectview_mail_package
	 * @param array $context mode, system_names, existing (row from panel_domains
	 *                       or null), socket (bool: socket and paths valid),
	 *                       apache (bool), le (bool: Let's Encrypt on in Froxlor)
	 * @return array{action:string, status:string, message:string}
	 *               action: create | record | maintain | nothing
	 */
	public static function plan(string $domain, string $kind, ?array $entry, array $context, callable $dnsOk, int $now): array
	{
		$name = $kind . '.' . $domain;
		$existing = $context['existing'] ?? null;
		$status = $entry['status'] ?? null;

		if ($status === 'created') {
			// A different ID means: the customer deleted ours and created one
			// of the same name. That one is theirs, so hands off.
			if ($existing === null || (int)$existing['id'] !== (int)$entry['subdomainid']) {
				return ['action' => 'record', 'status' => 'deleted', 'message' => Lang::t('Will not be created again.')];
			}
			return in_array($kind, self::WITH_PHP, true)
				? ['action' => 'maintain', 'status' => 'created', 'message' => '']
				: ['action' => 'nothing', 'status' => 'created', 'message' => ''];
		}
		if ($status === 'deleted' || $status === 'existing') {
			return ['action' => 'nothing', 'status' => $status, 'message' => (string)($entry['message'] ?? '')];
		}

		// In mode "off", what was created keeps being maintained; nothing new is planned.
		if ($context['mode'] === 'off') {
			return ['action' => 'nothing', 'status' => (string)$status, 'message' => (string)($entry['message'] ?? '')];
		}
		if (in_array($name, $context['system_names'], true)) {
			return ['action' => 'record', 'status' => 'skipped', 'message' => Lang::t('The name belongs to the panel (hostname or Froxlor alias).')];
		}
		if ($existing !== null) {
			return ['action' => 'record', 'status' => 'existing', 'message' => Lang::t('Left untouched.')];
		}
		if (in_array($kind, self::WITH_PHP, true)) {
			if (!$context['apache']) {
				return ['action' => 'record', 'status' => 'skipped', 'message' => Lang::t('Only works with Apache as the web server.')];
			}
			if (!$context['socket']) {
				return ['action' => 'record', 'status' => 'skipped', 'message' => Lang::t('The settings are missing the PHP-FPM socket for Roundcube, or a path is invalid.')];
			}
		}
		if (!$context['le']) {
			return ['action' => 'record', 'status' => 'skipped', 'message' => Lang::t('Let\'s Encrypt is turned off in Froxlor.')];
		}
		if ($context['mode'] !== 'on') {
			return ['action' => 'record', 'status' => 'planned', 'message' => ''];
		}
		if (in_array($status, ['waiting_dns', 'failed'], true) && $now - (int)$entry['changed_at'] < self::RETRY) {
			return ['action' => 'nothing', 'status' => $status, 'message' => (string)$entry['message']];
		}
		if (!$dnsOk()) {
			return ['action' => 'record', 'status' => 'waiting_dns', 'message' => Lang::t('%s does not point to this server in DNS.', $name)];
		}

		return ['action' => 'create', 'status' => 'created', 'message' => ''];
	}

	/**
	 * Apache lines for the special settings of a subdomain.
	 *
	 * The ACME challenge comes first: aliases in the vhost take precedence
	 * over the global alias from Froxlor's acme.conf, and "Alias /" would
	 * otherwise also swallow Let's Encrypt's validation requests — renewal
	 * would fail.
	 */
	public static function vhostLines(string $kind, array $paths): string
	{
		$challenge = rtrim($paths['challenge'], '/') . '/.well-known/acme-challenge';
		$handler = 'proxy:unix:' . $paths['socket'] . '|fcgi://froxlor-project-view-webapps';
		$lines = [
			self::MARKER . ': ' . $kind . ' (maintained automatically, manual changes will be lost)',
			'Alias "/.well-known/acme-challenge" "' . $challenge . '"',
		];

		if ($kind === 'webmail') {
			$roundcube = rtrim($paths['roundcube'], '/');
			array_push(
				$lines,
				'Alias "/" "' . $roundcube . '/"',
				'DirectoryIndex index.php',
				'<Directory "' . $roundcube . '">',
				'	Options +FollowSymLinks -Indexes',
				'	AllowOverride All',
				'	Require all granted',
				'	<FilesMatch "\.php$">',
				'		SetHandler "' . $handler . '"',
				'	</FilesMatch>',
				'</Directory>'
			);
		} else {
			$script = rtrim($paths['package'], '/') . '/autoconfig.php';
			array_push(
				$lines,
				'AliasMatch "(?i)^/(\.well-known/autoconfig/)?mail/config-v1\.1\.xml$" "' . $script . '"',
				'AliasMatch "(?i)^/autodiscover/autodiscover\.xml$" "' . $script . '"',
				'<Directory "' . rtrim($paths['package'], '/') . '">',
				'	Require all denied',
				'	<Files "autoconfig.php">',
				'		Require all granted',
				'		SetHandler "' . $handler . '"',
				'	</Files>',
				'</Directory>'
			);
		}

		return implode("\n", $lines);
	}

	/** @return string off | dry_run | on */
	public static function mode(): string
	{
		$mode = (string)Settings::Get('ext_projectview.mail_package_mode');

		return in_array($mode, ['off', 'dry_run', 'on'], true) ? $mode : 'dry_run';
	}

	/**
	 * @param callable|null $dns fn(string $host, int $domainid): bool — for tests
	 * @return array{created:int, maintained:int, failed:int}
	 */
	public static function run(?callable $dns = null, ?int $now = null): array
	{
		$dns ??= [SubdomainSsl::class, 'dnsPointsHere'];
		$now ??= time();
		$counts = ['created' => 0, 'maintained' => 0, 'failed' => 0];
		$paths = self::paths();
		$context = [
			'mode' => self::mode(),
			'system_names' => self::systemNames(),
			'socket' => self::pathsValid($paths),
			'apache' => Settings::Get('system.webserver') === 'apache2',
			'le' => (string)Settings::Get('system.leenabled') === '1',
		];
		$entries = self::entries();
		// Free subdomains per customer, counted down during the run: if a
		// customer has several mail domains, the first one uses up what the
		// second would still see.
		$free = [];

		foreach (self::domains() as $domain) {
			$customer = (int)$domain['customerid'];
			$free[$customer] ??= (int)$domain['subdomains'] === -1 ? PHP_INT_MAX : (int)$domain['subdomains'] - (int)$domain['subdomains_used'];

			$plans = [];
			foreach (array_keys(self::KINDS) as $kind) {
				$name = $kind . '.' . $domain['domain'];
				$entry = $entries[(int)$domain['id']][$kind] ?? null;
				$existing = self::existing($name);
				$plans[$kind] = [$name, $entry, $existing];
			}

			// Better nothing than half a package: if the customer's subdomain
			// quota is not enough for everything that is missing, nothing is
			// created. Froxlor checks the quota for admins too. Counted before
			// any DNS lookup, as if DNS pointed here everywhere — otherwise a
			// customer without quota would trigger lookups every minute.
			$possible = 0;
			foreach ($plans as $kind => [$name, $entry, $existing]) {
				$probe = self::plan($domain['domain'], $kind, $entry, ['existing' => $existing] + $context, static fn() => true, $now);
				$possible += $probe['action'] === 'create' ? 1 : 0;
			}
			$tooFew = $possible > $free[$customer];

			foreach ($plans as $kind => [$name, $entry, $existing]) {
				$plan = self::plan($domain['domain'], $kind, $entry, ['existing' => $existing] + $context, static fn() => $tooFew || $dns($name, (int)$domain['id']), $now);
				if ($tooFew && $plan['action'] === 'create') {
					$plan = ['action' => 'record', 'status' => 'skipped', 'message' => Lang::t('The customer\'s subdomain quota is not enough (%d free, %d needed).', max(0, $free[$customer]), $possible)];
				}
				$plans[$kind] = [$plan, $entry, $existing];
			}

			foreach ($plans as $kind => [$plan, $entry, $existing]) {
				try {
					switch ($plan['action']) {
						case 'create':
							$id = self::create($domain, $kind, $paths);
							// Record immediately, before anything else: if something
							// fails afterwards, the next run catches up instead of
							// taking the subdomain for someone else's.
							self::record((int)$domain['id'], $kind, $id, 'created', '', $now, $entry);
							$entry = ['status' => 'created', 'subdomainid' => $id] + ($entry ?? ['first_attempt_at' => $now]);
							$free[$customer]--;
							$counts['created']++;
							self::withoutWwwAlias($id);
							if (in_array($kind, self::WITH_PHP, true)) {
								self::maintain(self::existing($kind . '.' . $domain['domain']), $kind, $paths);
							}
							break;
						case 'maintain':
							if (self::maintain($existing, $kind, $paths)) {
								$counts['maintained']++;
							}
							break;
						case 'record':
							$subId = $plan['status'] === 'deleted' ? (int)$entry['subdomainid'] : (int)($existing['id'] ?? 0);
							if ($entry === null || $entry['status'] !== $plan['status'] || $entry['message'] !== $plan['message'] || $plan['status'] === 'waiting_dns') {
								self::record((int)$domain['id'], $kind, $subId, $plan['status'], $plan['message'], $now, $entry);
							}
							break;
					}
				} catch (\Throwable $e) {
					// Once created it stays "created"; only the maintenance is missing.
					if (($entry['status'] ?? '') !== 'created') {
						self::record((int)$domain['id'], $kind, (int)($entry['subdomainid'] ?? 0), 'failed', $e->getMessage(), $now, $entry);
					}
					$counts['failed']++;
				}
			}
		}

		return $counts;
	}

	/**
	 * Restores the Apache lines of a main domain's subdomains right away.
	 * Froxlor's Domains::update overwrites the special settings and PHP
	 * switches of all subdomains on every edit of the main domain; Project
	 * View calls this after its own changes, changes made in Froxlor's form
	 * are caught up by the next cron run.
	 */
	public static function maintainForDomain(int $domainid): void
	{
		$paths = self::paths();
		if (!self::pathsValid($paths)) {
			return;
		}
		$stmt = Database::prepare("
			SELECT `m`.`kind`, `d`.`id`, `d`.`phpenabled`, `d`.`specialsettings`, `d`.`ssl_specialsettings`, `d`.`include_specialsettings`,
			       `d`.`wwwserveralias`, `d`.`iswildcarddomain`
			FROM `ext_projectview_mail_package` `m`
			JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `m`.`subdomainid`
			WHERE `m`.`domainid` = :id AND `m`.`status` = 'created'
		");
		Database::pexecute($stmt, ['id' => $domainid]);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
			if (in_array($sub['kind'], self::WITH_PHP, true)) {
				self::maintain($sub, $sub['kind'], $paths);
			}
		}
	}

	/** Status per kind for one domain, for Project View. */
	public static function statusFor(int $domainid): array
	{
		$stmt = Database::prepare("SELECT * FROM `ext_projectview_mail_package` WHERE `domainid` = :id");
		Database::pexecute($stmt, ['id' => $domainid]);
		$status = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$status[$row['kind']] = $row;
		}

		return $status;
	}

	/** The paths that go into the Apache lines. */
	public static function paths(): array
	{
		return [
			'challenge' => (string)Settings::Get('system.letsencryptchallengepath'),
			'roundcube' => (string)Settings::Get('ext_projectview.mail_package_roundcube_dir'),
			'socket' => trim((string)Settings::Get('ext_projectview.mail_package_php_socket')),
			'package' => rtrim(Froxlor::getInstallDir(), '/') . '/lib/Froxlor/Ext/ProjectView/MailPackage',
		];
	}

	/**
	 * Socket and paths end up in double quotes in the Apache configuration.
	 * A " or \ in them (Froxlor's path validation lets both through) would
	 * keep Apache from starting.
	 */
	public static function pathsValid(array $paths): bool
	{
		foreach (['challenge', 'roundcube', 'socket', 'package'] as $field) {
			if (!preg_match('#^/[^"\\\\\x00-\x1f\x7f]*$#D', (string)$paths[$field])) {
				return false;
			}
		}

		return true;
	}

	/** Hostname and Froxlor aliases: these names belong to the panel. */
	private static function systemNames(): array
	{
		$names = [strtolower((string)Settings::Get('system.hostname'))];
		foreach (explode(',', (string)Settings::Get('system.froxloraliases')) as $alias) {
			$alias = strtolower(trim($alias));
			if ($alias !== '') {
				$names[] = $alias;
			}
		}

		return $names;
	}

	/**
	 * E-mail domains that get a mail package. "E-mail only" is excluded
	 * because Froxlor allows no subdomains below it; the customer's standard
	 * subdomain lives below the hostname and is not included.
	 */
	private static function domains(): array
	{
		$stmt = Database::query("
			SELECT `d`.`id`, `d`.`domain`, `d`.`customerid`, `c`.`subdomains`, `c`.`subdomains_used`
			FROM `" . TABLE_PANEL_DOMAINS . "` `d`
			JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`customerid` = `d`.`customerid`
			WHERE `d`.`parentdomainid` = 0
			  AND `d`.`isemaildomain` = 1
			  AND `d`.`email_only` = 0
			  AND `d`.`deactivated` = 0
			  AND `d`.`aliasdomain` IS NULL
			  AND `d`.`id` <> `c`.`standardsubdomain`
			  AND `c`.`deactivated` = 0
			ORDER BY `d`.`id`
		");

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	private static function entries(): array
	{
		$entries = [];
		foreach (Database::query("SELECT * FROM `ext_projectview_mail_package`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$entries[(int)$row['domainid']][$row['kind']] = $row;
		}

		return $entries;
	}

	private static function existing(string $name): ?array
	{
		$stmt = Database::prepare("
			SELECT `id`, `phpenabled`, `specialsettings`, `ssl_specialsettings`, `include_specialsettings`,
			       `wwwserveralias`, `iswildcarddomain`
			FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `domain` = :name
		");
		$row = Database::pexecute_first($stmt, ['name' => $name]);

		return $row ?: null;
	}

	private static function create(array $domain, string $kind, array $paths): int
	{
		$params = [
			'customerid' => (int)$domain['customerid'],
			'subdomain' => $kind,
			'domain' => $domain['domain'],
			'sslenabled' => 1,
			'letsencrypt' => 1,
			'ssl_redirect' => 1,
		];
		// Without a socket there is no webmail., so mail. stays an empty page.
		// Otherwise its own empty directory: without a path Froxlor would take
		// the customer's whole web directory, and that must never appear under
		// these names, not even if the Apache lines go missing at some point.
		if ($kind === 'mail' && self::pathsValid($paths)) {
			$params['url'] = 'https://webmail.' . $domain['domain'] . '/';
		} else {
			$params['path'] = $kind . '.' . $domain['domain'];
		}

		$response = SubDomains::getLocal(AdminContext::forCustomer((int)$domain['customerid']), $params)->add();
		$id = (int)(json_decode($response, true)['data']['id'] ?? 0);
		if ($id <= 0) {
			throw new \RuntimeException(Lang::t('Froxlor returned no ID for %s.', $kind . '.' . $domain['domain']));
		}

		return $id;
	}

	private static function withoutWwwAlias(int $id): void
	{
		// SubDomains::add copies the main domain's www alias without a way to
		// deselect it. Nobody needs www.webmail.customer.example, and as a
		// second name in the certificate it would only be one more place where
		// issuance can fail. Directly instead of via SubDomains::update: that
		// would turn ssl_redirect=2 ("only once the certificate exists") into 1
		// immediately and redirect to HTTPS without a certificate.
		$stmt = Database::prepare("UPDATE `" . TABLE_PANEL_DOMAINS . "` SET `wwwserveralias` = 0, `iswildcarddomain` = 0 WHERE `id` = :id");
		Database::pexecute($stmt, ['id' => $id]);
		Cronjob::inserttask(TaskId::REBUILD_VHOST);
	}

	/**
	 * Sets the Apache lines if they are missing or outdated.
	 *
	 * Directly in the database, because Froxlor's API accepts special
	 * settings only for main domains. Every edit of the main domain
	 * (Domains::update) resets the special settings and PHP switches of all
	 * subdomains — the next run restores them, after changes in Project View
	 * immediately (maintainForDomain).
	 *
	 * The customer's PHP is off: Roundcube and the autoconfiguration run in
	 * the server's own pool, not with the customer's privileges.
	 *
	 * @return bool true if something was changed
	 */
	private static function maintain(?array $sub, string $kind, array $paths): bool
	{
		if ($sub === null || !self::pathsValid($paths)) {
			return false;
		}
		$wanted = self::vhostLines($kind, $paths);
		if ($sub['specialsettings'] === $wanted && (int)$sub['phpenabled'] === 0
			&& (string)$sub['ssl_specialsettings'] === '' && (int)$sub['include_specialsettings'] === 1
			&& (int)$sub['wwwserveralias'] === 0 && (int)$sub['iswildcarddomain'] === 0) {
			return false;
		}

		$stmt = Database::prepare("
			UPDATE `" . TABLE_PANEL_DOMAINS . "`
			SET `specialsettings` = :lines, `ssl_specialsettings` = '', `include_specialsettings` = 1, `phpenabled` = 0,
			    `wwwserveralias` = 0, `iswildcarddomain` = 0
			WHERE `id` = :id
		");
		Database::pexecute($stmt, ['lines' => $wanted, 'id' => (int)$sub['id']]);
		Cronjob::inserttask(TaskId::REBUILD_VHOST);

		return true;
	}

	private static function record(int $domainid, string $kind, int $subdomainid, string $status, string $message, int $now, ?array $entry): void
	{
		$stmt = Database::prepare("
			INSERT INTO `ext_projectview_mail_package`
				(`domainid`, `kind`, `subdomainid`, `status`, `message`, `first_attempt_at`, `changed_at`)
			VALUES (:domainid, :kind, :sub, :status, :message, :first, :now)
			ON DUPLICATE KEY UPDATE
				`subdomainid` = VALUES(`subdomainid`),
				`status` = VALUES(`status`),
				`message` = VALUES(`message`),
				`changed_at` = VALUES(`changed_at`)
		");
		Database::pexecute($stmt, [
			'domainid' => $domainid,
			'kind' => $kind,
			'sub' => $subdomainid,
			'status' => $status,
			'message' => SubdomainSsl::shorten($message),
			'first' => $entry !== null ? (int)$entry['first_attempt_at'] : $now,
			'now' => $now,
		]);
	}
}
