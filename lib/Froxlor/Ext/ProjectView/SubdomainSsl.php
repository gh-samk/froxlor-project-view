<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Api\Commands\SubDomains;
use Froxlor\Database\Database;
use Froxlor\Domain\Domain;
use Froxlor\PhpHelper;
use Froxlor\Settings;
use PDO;

/**
 * Turns on Let's Encrypt for subdomains whose main domain has a
 * certificate.
 *
 * Froxlor creates subdomains with letsencrypt=0 and then serves the main
 * domain's certificate (Cron/Http/DomainSSL.php). That never matches the
 * subdomain's name — browsers and strict clients reject it.
 *
 * Every subdomain is switched exactly once; the tracking table
 * ext_projectview_ssl_subdomains records which ones have been handled. Whoever
 * deliberately turns Let's Encrypt off afterwards keeps it that way. Froxlor
 * itself also turns Let's Encrypt off again when its DNS check fails — so the
 * cron checks DNS itself beforehand and waits instead of letting Froxlor fail.
 */
class SubdomainSsl
{
	/** While a subdomain waits for DNS, it is re-checked this often. */
	public const DNS_RETRY = 3600;

	/** After this the cron gives up and reports it in Project View. */
	public const DNS_GIVE_UP = 7 * 86400;

	public const STATUS = [
		'done' => 'Let\'s Encrypt turned on automatically',
		'waiting_dns' => 'Certificate waiting for DNS',
		'given_up' => 'No certificate, gave up',
		'failed' => 'Certificate: error while turning on',
	];

	/** The status labels in the current language (STATUS holds the English source texts). */
	public static function statusLabels(): array
	{
		return [
			'done' => Lang::t('Let\'s Encrypt turned on automatically'),
			'waiting_dns' => Lang::t('Certificate waiting for DNS'),
			'given_up' => Lang::t('No certificate, gave up'),
			'failed' => Lang::t('Certificate: error while turning on'),
		];
	}

	/**
	 * What should happen to a subdomain.
	 *
	 * @param array|null $entry row from ext_projectview_ssl_subdomains or null
	 * @param callable $dnsOk only called when DNS actually matters
	 * @return string enable | wait | give_up | nothing
	 */
	public static function decide(?array $entry, callable $dnsOk, int $now): string
	{
		// Errors (e.g. a locked table) are retried hourly like missing DNS
		// and given up after seven days.
		if ($entry !== null) {
			if (!in_array($entry['status'], ['waiting_dns', 'failed'], true)) {
				return 'nothing';
			}
			if ($now - (int)$entry['last_attempt_at'] < self::DNS_RETRY) {
				return 'nothing';
			}
			if ($entry['status'] === 'failed' && $now - (int)$entry['first_attempt_at'] >= self::DNS_GIVE_UP) {
				return 'give_up';
			}
		}
		if ($dnsOk()) {
			return 'enable';
		}
		if ($entry !== null && $now - (int)$entry['first_attempt_at'] >= self::DNS_GIVE_UP) {
			return 'give_up';
		}

		return 'wait';
	}

	/**
	 * Subdomains without their own certificate below a main domain with
	 * Let's Encrypt that are still pending (new or waiting).
	 *
	 * Wildcard subdomains are deliberately excluded: Froxlor does not issue
	 * Let's Encrypt certificates for them (error nowildcardwithletsencrypt).
	 */
	public static function candidates(): array
	{
		$stmt = Database::query("
			SELECT `s`.`id`, `s`.`domain`, `s`.`customerid`, `s`.`wwwserveralias`, `s`.`ssl_redirect`,
			       `p`.`ssl_redirect` AS `parent_ssl_redirect`,
			       `t`.`status`, `t`.`attempts`, `t`.`first_attempt_at`, `t`.`last_attempt_at`, `t`.`message`
			FROM `" . TABLE_PANEL_DOMAINS . "` `s`
			JOIN `" . TABLE_PANEL_DOMAINS . "` `p` ON `p`.`id` = `s`.`parentdomainid`
			LEFT JOIN `ext_projectview_ssl_subdomains` `t` ON `t`.`domainid` = `s`.`id`
			WHERE `s`.`parentdomainid` > 0
			  AND `s`.`letsencrypt` = 0
			  AND `s`.`ssl_enabled` = 1
			  AND `s`.`iswildcarddomain` = 0
			  AND `s`.`deactivated` = 0
			  AND `s`.`email_only` = 0
			  AND `s`.`aliasdomain` IS NULL
			  AND `p`.`letsencrypt` = 1
			  AND (`t`.`domainid` IS NULL OR `t`.`status` IN ('waiting_dns', 'failed'))
			ORDER BY `s`.`id`
		");

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @param callable|null $dns fn(string $host, int $domainid): bool — for tests
	 * @return array{enabled:int, waiting:int, given_up:int, failed:int}
	 */
	public static function run(?callable $dns = null, ?int $now = null): array
	{
		$dns ??= [self::class, 'dnsPointsHere'];
		$now ??= time();
		$counts = ['enabled' => 0, 'waiting' => 0, 'given_up' => 0, 'failed' => 0];

		foreach (self::candidates() as $sub) {
			$entry = $sub['status'] === null ? null : $sub;
			// With a www alias, www.<subdomain> is in the certificate as well.
			// If it does not point here, the whole issuance fails — so check both.
			$dnsOk = static fn() => $dns((string)$sub['domain'], (int)$sub['id'])
				&& ((int)$sub['wwwserveralias'] !== 1 || $dns('www.' . $sub['domain'], (int)$sub['id']));
			$what = self::decide($entry, $dnsOk, $now);

			switch ($what) {
				case 'enable':
					try {
						SubDomains::getLocal(AdminContext::forCustomer((int)$sub['customerid']), [
							'id' => (int)$sub['id'],
							'customerid' => (int)$sub['customerid'],
							'letsencrypt' => 1,
							// In Froxlor, 2 means "redirect as soon as the
							// certificate exists" — so it means on. A redirect the
							// subdomain already has is kept.
							'ssl_redirect' => ((int)$sub['parent_ssl_redirect'] > 0 || (int)$sub['ssl_redirect'] > 0) ? 1 : 0,
						])->update();
						self::record((int)$sub['id'], 'done', '', $now, $entry);
						$counts['enabled']++;
					} catch (\Throwable $e) {
						self::record((int)$sub['id'], 'failed', $e->getMessage(), $now, $entry);
						$counts['failed']++;
					}
					break;
				case 'wait':
					self::record((int)$sub['id'], 'waiting_dns', Lang::t('DNS does not point to this server'), $now, $entry);
					$counts['waiting']++;
					break;
				case 'give_up':
					$reason = ($entry['status'] ?? '') === 'failed'
						? Lang::t('Unsuccessful for 7 days: %s', $entry['message'] ?? '')
						: Lang::t('DNS has not pointed to this server for 7 days');
					self::record((int)$sub['id'], 'given_up', $reason, $now, $entry);
					$counts['given_up']++;
					break;
			}
		}

		return $counts;
	}

	/**
	 * Does the name resolve to an IP of this server? The same check Froxlor
	 * performs before issuing (AcmeSh::validateDns), but independent of
	 * system.le_domain_dnscheck: without matching DNS, Let's Encrypt's HTTP
	 * challenge fails either way.
	 */
	public static function dnsPointsHere(string $host, int $domainid): bool
	{
		$resolver = (string)Settings::Get('system.le_domain_dnscheck_resolver');
		$ips = PhpHelper::gethostbynamel6($host, true, $resolver !== '' ? $resolver : null);
		if (!$ips) {
			return false;
		}

		return count(array_intersect(Domain::getIpsOfDomain($domainid), $ips)) > 0;
	}

	/** Status per subdomain ID, for Project View. */
	public static function statusFor(array $subdomainIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $subdomainIds)));
		if (!$ids) {
			return [];
		}
		$stmt = Database::prepare("
			SELECT `domainid`, `status`, `message`, `last_attempt_at`
			FROM `ext_projectview_ssl_subdomains`
			WHERE `domainid` IN (" . implode(',', $ids) . ")
		");
		Database::pexecute($stmt);
		$status = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$status[(int)$row['domainid']] = $row;
		}

		return $status;
	}

	/**
	 * Error message for the table and the log: one line, at most 255
	 * characters. API errors can contain line breaks and HTML.
	 */
	public static function shorten(string $text): string
	{
		$text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

		return mb_substr($text, 0, 255);
	}

	private static function record(int $domainid, string $status, string $message, int $now, ?array $entry): void
	{
		$stmt = Database::prepare("
			INSERT INTO `ext_projectview_ssl_subdomains`
				(`domainid`, `status`, `attempts`, `first_attempt_at`, `last_attempt_at`, `message`)
			VALUES (:id, :status, 1, :first, :last, :message)
			ON DUPLICATE KEY UPDATE
				`status` = VALUES(`status`),
				`attempts` = `attempts` + 1,
				`last_attempt_at` = VALUES(`last_attempt_at`),
				`message` = VALUES(`message`)
		");
		Database::pexecute($stmt, [
			'id' => $domainid,
			'status' => $status,
			'first' => $entry !== null ? (int)$entry['first_attempt_at'] : $now,
			'last' => $now,
			'message' => self::shorten($message),
		]);
	}
}
