<?php

/**
 * Removes the traces of Project View from the database, the customer sites
 * and the mail configuration.
 *
 *   php migrations/remove.php /var/www/froxlor           unregister cron jobs, remove autologin plugins and mail SNI
 *   php migrations/remove.php /var/www/froxlor --purge   additionally drop tables and settings
 *
 * Always, also without --purge:
 * - The cron jobs are unregistered. If they stayed, Froxlor's scheduler would
 *   run into classes that no longer exist.
 * - The one-time login plugins are removed from the customers' WordPress
 *   installations. Without Project View nobody creates login links any more,
 *   and foreign code should not be left orphaned in customer sites.
 * - The mail SNI is taken down: without the cron the certificates would no
 *   longer be renewed and would expire after three months. Postfix and
 *   Dovecot then serve the panel's certificate for all names again. The
 *   subdomains of the mail package stay — they belong to the customer now.
 *
 * Only this repository's own code is loaded (connection.php, MailSni.php);
 * nothing from the Froxlor directory is included.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/MailSni.php';

use Froxlor\Ext\ProjectView\MailSni;

/** File name of the must-use plugin (Froxlor\Ext\ProjectView\WordpressLogin::FILE). */
const PV_LOGIN_PLUGIN = 'froxlor-project-view-login.php';

$froxlor = $argv[1] ?? '';
$purge = in_array('--purge', array_slice($argv, 2), true);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

[$pdo, $db, $host, $user] = pv_connect($froxlor);
echo "  Database '$db' on '$host' as '$user'\n";

$pdo->exec("DELETE FROM `cronjobs_run` WHERE `module` = 'ext/projectview'");
echo "  Cron jobs unregistered\n";

$exists = static function (string $table, ?string $column = null) use ($pdo): bool {
	if ($column === null) {
		$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
		$stmt->execute([$table]);
	} else {
		$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
		$stmt->execute([$table, $column]);
	}
	return (int)$stmt->fetchColumn() > 0;
};

if ($exists('ext_projectview_wp_installs', 'login_installed')) {
	$installs = $pdo->query("
		SELECT `i`.`path`, `c`.`guid`
		FROM `ext_projectview_wp_installs` `i`
		JOIN `panel_domains` `d` ON `d`.`id` = `i`.`domainid`
		JOIN `panel_customers` `c` ON `c`.`customerid` = `d`.`customerid`
		WHERE `i`.`login_installed` = 1
	")->fetchAll(PDO::FETCH_ASSOC);
	$setpriv = is_executable('/usr/bin/setpriv') ? '/usr/bin/setpriv' : (is_executable('/bin/setpriv') ? '/bin/setpriv' : null);
	foreach ($installs as $install) {
		$file = rtrim((string)$install['path'], '/') . '/wp-content/mu-plugins/' . PV_LOGIN_PLUGIN;
		$guid = (int)$install['guid'];
		// Delete as the customer, like when creating it: if they redirect the
		// directories with symbolic links, that only hits what they may touch anyway.
		if ($isRoot && $setpriv !== null && $guid >= 1000) {
			$command = sprintf('%s --reuid=%d --regid=%d --clear-groups -- rm -f -- %s 2>&1', $setpriv, $guid, $guid, escapeshellarg($file));
			$output = [];
			exec($command, $output, $code);
			echo $code === 0 ? "  Autologin plugin removed: $file\n" : "  NOT removed: $file\n";
		} else {
			echo "  Please remove by hand (not started as root): $file\n";
		}
	}
	$pdo->exec("UPDATE `ext_projectview_wp_installs` SET `login_installed` = 0, `login_secret_key` = NULL");
}

$sni = MailSni::DEFAULTS;
if (is_file($sni['postfix_map']) || is_file($sni['postfix_map'] . '.db') || is_file($sni['dovecot_conf']) || is_dir($sni['dir'])) {
	if ($isRoot) {
		$result = MailSni::teardown();
		foreach ($result as $part => $state) {
			echo "  Mail SNI $part: $state\n";
		}
		if (str_starts_with($result['postfix'], 'ERROR')) {
			echo "  Please remove {$sni['postfix_map']} from tls_server_sni_maps in main.cf by hand;\n";
			echo "  the table and the keys stay until then.\n";
		}
	} else {
		echo "  Please repeat as root: mail SNI ({$sni['postfix_map']}, {$sni['dovecot_conf']}) is still set up\n";
	}
}

if (!$purge) {
	echo "  Tables kept (use --purge to drop them as well)\n";
	exit(0);
}

foreach (['ext_projectview_mail_package', 'ext_projectview_ssl_subdomains', 'ext_projectview_wp_jobs', 'ext_projectview_stats', 'ext_projectview_resources', 'ext_projectview_wp_installs', 'ext_projectview_wp_latest'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS `" . $table . "`");
	echo "  dropped: $table\n";
}
$pdo->exec("DELETE FROM `panel_settings` WHERE `settinggroup` = 'ext_projectview'");
echo "  Settings deleted\n";
