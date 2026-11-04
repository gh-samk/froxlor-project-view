<?php

/**
 * Let's Encrypt for subdomains and the mail package per mail domain.
 *
 * Two bookkeeping tables, so the cron touches every subdomain only once:
 * whoever deliberately turns Let's Encrypt off afterwards, or deletes a
 * created subdomain, keeps it that way — the cron sees the entry and leaves
 * it alone.
 *
 * A PHP migration like 003, so it is idempotent on MariaDB and MySQL.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

return static function (PDO $pdo): void {
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS `ext_projectview_ssl_subdomains` (
		  `domainid`         int unsigned NOT NULL,
		  `status`           varchar(16)  NOT NULL DEFAULT 'waiting_dns',
		  `attempts`         int unsigned NOT NULL DEFAULT 0,
		  `first_attempt_at` int NOT NULL DEFAULT 0,
		  `last_attempt_at`  int NOT NULL DEFAULT 0,
		  `message`          varchar(255) NOT NULL DEFAULT '',
		  PRIMARY KEY (`domainid`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	$pdo->exec("
		CREATE TABLE IF NOT EXISTS `ext_projectview_mail_package` (
		  `id`               int unsigned NOT NULL AUTO_INCREMENT,
		  `domainid`         int unsigned NOT NULL,
		  `kind`             varchar(16)  NOT NULL,
		  `subdomainid`      int unsigned NOT NULL DEFAULT 0,
		  `status`           varchar(16)  NOT NULL DEFAULT 'planned',
		  `message`          varchar(255) NOT NULL DEFAULT '',
		  `first_attempt_at` int NOT NULL DEFAULT 0,
		  `changed_at`       int NOT NULL DEFAULT 0,
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `domain_kind` (`domainid`, `kind`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	// Every minute: whoever creates a subdomain should not wait long for the
	// certificate. The run itself is cheap as long as there is nothing to do.
	$pdo->prepare("
		INSERT INTO `cronjobs_run` (`module`, `cronfile`, `cronclass`, `interval`, `isactive`, `desc_lng_key`)
		SELECT ?, ?, ?, ?, 1, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `cronjobs_run` WHERE `cronfile` = ?)
	")->execute([
		'ext/projectview',
		'ext_projectview_domaintls',
		'\\Froxlor\\Ext\\ProjectView\\Cron\\DomainTlsCron',
		'1 MINUTE',
		'Project View: Let\'s Encrypt for subdomains and mail package',
		'ext_projectview_domaintls',
	]);

	// Froxlor rewrites /etc/cron.d/froxlor only for task 99. Without it the
	// new job would only be in the table and never run.
	$pdo->exec("
		INSERT INTO `panel_tasks` (`type`, `data`)
		SELECT 99, '' FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_tasks` WHERE `type` = 99)
	");

	// The mail package starts in dry-run mode: first see what would be created.
	$settings = [
		'ssl_subdomains_auto' => '1',
		'mail_package_mode' => 'dry_run',
		'mail_package_roundcube_dir' => '/var/lib/roundcube/public_html/',
		'mail_package_php_socket' => '',
		// Last reasons why a mail. certificate did not reach Postfix/Dovecot
		// (JSON). The cron only reports changes, not every minute.
		'mail_sni_notes' => '{}',
	];
	$stmt = $pdo->prepare("
		INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`)
		SELECT 'ext_projectview', ?, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_settings` WHERE `settinggroup` = 'ext_projectview' AND `varname` = ?)
	");
	foreach ($settings as $varname => $value) {
		$stmt->execute([$varname, $value, $varname]);
	}
};
