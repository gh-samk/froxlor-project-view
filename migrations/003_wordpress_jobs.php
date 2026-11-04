<?php

/**
 * WordPress jobs: check, back up, update and one-time login.
 *
 * A PHP instead of an SQL migration because it adds columns: MariaDB knows
 * "ADD COLUMN IF NOT EXISTS", MySQL does not. Here information_schema is
 * consulted first — so the migration stays idempotent on both and may run
 * any number of times.
 *
 * Called by migrations/apply.php, which passes its connection.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

return static function (PDO $pdo): void {
	$hasColumn = static function (string $table, string $column) use ($pdo): bool {
		$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
		$stmt->execute([$table, $column]);
		return (int)$stmt->fetchColumn() > 0;
	};

	// Result of the last check and the autologin key per installation.
	$columns = [
		'siteurl' => 'VARCHAR(255) NULL',
		'admin_users' => 'TEXT NULL',
		'update_core' => 'VARCHAR(32) NULL',
		'update_plugins' => 'INT NOT NULL DEFAULT 0',
		'update_themes' => 'INT NOT NULL DEFAULT 0',
		'update_details' => 'TEXT NULL',
		'checked_at' => 'INT NOT NULL DEFAULT 0',
		// Secret Ed25519 key (base64). Only the public key reaches the
		// WordPress installation — see lib/Froxlor/Ext/ProjectView/WordpressLogin.php.
		'login_secret_key' => 'VARCHAR(255) NULL',
		'login_installed' => 'TINYINT(1) NOT NULL DEFAULT 0',
	];
	foreach ($columns as $name => $definition) {
		if (!$hasColumn('ext_projectview_wp_installs', $name)) {
			$pdo->exec('ALTER TABLE `ext_projectview_wp_installs` ADD COLUMN `' . $name . '` ' . $definition);
		}
	}

	// Queue. The panel only adds entries here; they are executed by the cron.
	$pdo->exec("
		CREATE TABLE IF NOT EXISTS `ext_projectview_wp_jobs` (
		  `id`          int unsigned NOT NULL AUTO_INCREMENT,
		  `install_id`  int unsigned NOT NULL,
		  `domainid`    int unsigned NOT NULL,
		  `type`        varchar(32)  NOT NULL,
		  `status`      varchar(16)  NOT NULL DEFAULT 'queued',
		  `created_by`  varchar(255) NOT NULL DEFAULT '',
		  `created_at`  int NOT NULL DEFAULT 0,
		  `started_at`  int NOT NULL DEFAULT 0,
		  `finished_at` int NOT NULL DEFAULT 0,
		  `backup_path` varchar(255) NULL,
		  `log`         mediumtext NULL,
		  PRIMARY KEY (`id`),
		  KEY `install` (`install_id`),
		  KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	");

	// Every minute: nobody should wait longer for an update they started.
	// Parameters instead of an SQL literal, so the backslashes in the class
	// name are not swallowed by MySQL's string escaping.
	$pdo->prepare("
		INSERT INTO `cronjobs_run` (`module`, `cronfile`, `cronclass`, `interval`, `isactive`, `desc_lng_key`)
		SELECT ?, ?, ?, ?, 1, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `cronjobs_run` WHERE `cronfile` = ?)
	")->execute([
		'ext/projectview',
		'ext_projectview_wpjobs',
		'\\Froxlor\\Ext\\ProjectView\\Cron\\WordpressJobCron',
		'1 MINUTE',
		'Project View: run WordPress jobs',
		'ext_projectview_wpjobs',
	]);

	$settings = [
		'wpcli_path' => '',
		'wp_backup_dir' => '/var/backups/froxlor-project-view',
		'wp_backup_keep' => '3',
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
