<?php

/**
 * Parses and renders the Project View templates with Froxlor's own Twig
 * library — without a running Froxlor:
 *
 *   php tests/templates.php /path/to/froxlor
 *
 * Only vendor/autoload.php of the given Froxlor installation is used (for
 * Twig); nothing is written there. Froxlor's base layout and table macro are
 * replaced by minimal stubs. Rendering runs with strict_variables, in German
 * and in English, so a renamed or misspelled variable or key fails here
 * instead of silently rendering empty in production (Froxlor itself does not
 * use strict mode).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$froxlor = rtrim($argv[1] ?? '', '/');
if ($froxlor === '' || !is_file($froxlor . '/vendor/autoload.php')) {
	fwrite(STDERR, "Usage: php tests/templates.php /path/to/froxlor\n");
	exit(2);
}
require $froxlor . '/vendor/autoload.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Lang.php';

use Froxlor\Ext\ProjectView\Lang;

$stubs = new \Twig\Loader\ArrayLoader([
	'Froxlor/userarea.html.twig' => '{% block heading %}{% endblock %}{% block actions %}{% endblock %}{% block content %}{% endblock %}',
	'Froxlor/table/table.html.twig' => '{% macro table(listing) %}<table>{{ listing.table.tr|length }}</table>{% endmacro %}',
]);
$loader = new \Twig\Loader\ChainLoader([$stubs, new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates')]);
$twig = new \Twig\Environment($loader, ['cache' => false, 'autoescape' => 'html', 'strict_variables' => true]);
$twig->addFunction(new \Twig\TwigFunction('linker', static fn(array $p): string => '?' . http_build_query($p)));
Lang::registerTwig($twig);

$failures = 0;
$check = static function (bool $ok, string $text) use (&$failures): void {
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) {
		$failures++;
	}
};

echo "Syntax\n";
foreach (glob(__DIR__ . '/../templates/ProjectView/*.twig') as $file) {
	try {
		$twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($file), basename($file))));
		$check(true, basename($file));
	} catch (\Throwable $e) {
		$check(false, basename($file) . ': ' . $e->getMessage());
	}
}

$now = 1_800_000_000;
$project = [
	'id' => 1, 'domain' => 'customer.example', 'domain_ace' => 'customer.example', 'customerid' => 5,
	'deactivated' => 0, 'customer_label' => 'Example Ltd', 'loginname' => 'customer1', 'ssl_enabled' => 1,
	'letsencrypt' => 1, 'ssl_redirect' => 2, 'http2' => 1, 'hsts' => 0, 'iswildcarddomain' => 0,
	'wwwserveralias' => 1, 'documentroot' => '/var/customers/webs/customer1/site/', 'is_redirect' => false,
	'relative_path' => '/site/', 'docroot_rest' => 'site', 'customer_docroot' => '/var/customers/webs/customer1/',
	'customer_email' => 'owner@customer.example', 'customer_deactivated' => 0,
];
$integrityDetails = [
	'core_note' => null, 'plugins_checked' => 3, 'plugins_total' => 4, 'plugins_unverifiable' => ['premium-plugin'],
	'plugins_note' => null, 'uploads_note' => 'note', 'paths' => [['kind' => 'uploads', 'path' => 'wp-content/uploads/x.php'], ['kind' => 'modified', 'path' => 'wp-login.php']],
	'paths_total' => 60,
];
$wp = [
	'id' => 7, 'domainid' => 1, 'path' => '/var/customers/webs/customer1/site', 'version' => '6.4', 'latest' => '6.9',
	'outdated' => true, 'siteurl' => 'https://customer.example', 'admin_users' => ['admin'], 'update_core' => '6.9',
	'update_plugins' => 2, 'update_themes' => 1, 'update_details' => [], 'checked_at' => $now, 'login_installed' => 1,
	'integrity_checked_at' => $now, 'integrity_modified' => 1, 'integrity_added' => 0, 'integrity_missing' => 1,
	'integrity_uploads' => 1, 'integrity_details' => $integrityDetails, 'integrity_findings' => 3, 'busy' => true,
	'jobs' => [
		['id' => 1, 'type' => 'update_all', 'status' => 'done', 'created_by' => 'admin', 'created_at' => $now, 'started_at' => $now, 'finished_at' => $now, 'backup_path' => '/var/backups/froxlor-project-view/1/7-x', 'log' => 'log'],
		['id' => 2, 'type' => 'check', 'status' => 'queued', 'created_by' => 'Daily scan', 'created_at' => $now, 'started_at' => 0, 'finished_at' => 0, 'backup_path' => null, 'log' => null],
	],
];
$wpClean = array_merge($wp, ['integrity_findings' => 0, 'integrity_details' => array_merge($integrityDetails, ['core_note' => 'unreachable', 'plugins_note' => 'x']), 'login_installed' => 0, 'jobs' => []]);
$sub = [
	'id' => 11, 'domain' => 'shop.customer.example', 'domain_ace' => 'shop.customer.example', 'documentroot' => 'https://elsewhere.example/',
	'letsencrypt' => 0, 'ssl_redirect' => 0, 'ssl_enabled' => 1, 'deactivated' => 0, 'iswildcarddomain' => 0, 'wwwserveralias' => 0,
	'customer_docroot' => '/var/customers/webs/customer1/', 'is_redirect' => true, 'relative_path' => 'https://elsewhere.example/',
];
$mail = [
	'id' => 21, 'email' => 'info@customer.example', 'email_full' => 'info@customer.example', 'destination' => 'info@customer.example other@example.org',
	'popaccountid' => 3, 'iscatchall' => 1, 'description' => '{{ 7*7 }}', 'spam_tag_level' => '7.0', 'spam_kill_level' => '14.0',
	'rewrite_subject' => 1, 'bypass_spam' => 0, 'policy_greylist' => 1, 'quota' => 100, 'postfix' => 'Y', 'mboxsize' => 0,
	'has_account' => true, 'account_active' => true, 'forwarders' => [['index' => 1, 'target' => 'other@example.org']],
	'senders' => [['id' => 1, 'email' => 'info@customer.example', 'allowed_sender' => 'news@customer.example']],
];
$context = [
	'csrf_token' => 'token',
	'style_version' => 1,
	'project' => $project,
	'stats' => ['subdomains' => 1, 'emails' => 1, 'databases' => 1, 'ftps' => 1, 'wp_version' => '6.4', 'wp_count' => 2, 'wp_outdated' => true],
	'subdomains' => [$sub],
	'ssl_auto' => [11 => ['domainid' => 11, 'status' => 'failed', 'message' => 'DNS', 'last_attempt_at' => $now]],
	'ssl_auto_labels' => ['failed' => 'Certificate: error while enabling'],
	'mail_package' => [
		'mode' => 'dry_run',
		'rows' => [['kind' => 'mail', 'name' => 'mail.customer.example', 'purpose' => 'IMAP', 'status' => 'waiting_dns', 'text' => 'Waiting for DNS', 'message' => 'msg']],
		'waiting_dns' => true,
		'mail_server' => 'mail.customer.example',
	],
	'emails' => [$mail, array_merge($mail, ['id' => 22, 'has_account' => false, 'account_active' => false, 'forwarders' => [], 'senders' => []])],
	'databases' => [['id' => 31, 'databasename' => 'customer1sql1', 'description' => 'WordPress', 'dbserver' => 0]],
	'ftps' => [['id' => 41, 'username' => 'customer1ftp1', 'homedir' => '/var/customers/webs/customer1/', 'description' => '', 'login_enabled' => 'N']],
	'wordpress' => [$wp, $wpClean],
	'wp_types' => ['update_all' => 'Update everything', 'check' => 'Check'],
	'storage' => ['files' => 1024, 'db' => 2048, 'total' => 3072, 'computed_at' => $now, 'total_readable' => '3 MB', 'files_readable' => '1 MB', 'db_readable' => '2 MB'],
	'backup' => ['configured' => true, 'last_backup' => '01.01.2027 03:00', 'stale' => true, 'allowed' => true, 'restore_params' => ['customer' => 'customer1', 'project' => 1, 'scope' => 'all']],
	'flash' => ['type' => 'success', 'text' => 'Saved.'],
	'opt' => [
		'use_ssl' => true, 'may_absolute' => true, 'antispam' => true, 'quota' => true, 'senders' => true,
		'webmail_url' => 'https://webmail.example.org/', 'phpmyadmin_url' => 'https://pma.example.org/', 'adminer_url' => 'https://adminer.example.org/?x=1',
		'webftp_url' => 'https://ftp.example.org/', 'wpcli' => false,
	],
];
$unassigned = [
	'csrf_token' => 'token',
	'style_version' => 1,
	'databases' => [['id' => 3, 'databasename' => 'customer2sql1', 'customer_label' => 'B', 'description' => '', 'customerid' => 2,
		'suggestions' => [['id' => 4, 'domain' => 'b.example', 'domain_ace' => 'b.example']], 'domains' => [['id' => 4, 'domain' => 'b.example', 'domain_ace' => 'b.example']]]],
	'ftps' => [['id' => 2, 'username' => 'customer2ftp1', 'homedir' => '/x/', 'customer_label' => 'B', 'description' => 'd', 'customerid' => 2,
		'suggestions' => [], 'domains' => []]],
];
$list = ['style_version' => 1, 'listing' => ['table' => ['tr' => [1, 2]]], 'unassigned_count' => 3];

foreach (['en', 'de'] as $language) {
	Lang::set($language);
	echo "Rendering ($language)\n";
	foreach ([
		'detail.html.twig' => $context,
		'detail.html.twig (empty project)' => array_merge($context, [
			'subdomains' => [], 'emails' => [], 'databases' => [], 'ftps' => [], 'wordpress' => [], 'mail_package' => null,
			'storage' => null, 'backup' => null, 'flash' => null, 'ssl_auto' => [],
		]),
		'detail.html.twig (backup not set up)' => array_merge($context, ['backup' => ['configured' => false, 'last_backup' => null, 'stale' => true, 'allowed' => true, 'restore_params' => []]]),
		'unassigned.html.twig' => $unassigned,
		'list.html.twig' => $list,
	] as $name => $vars) {
		$template = explode(' ', $name)[0];
		try {
			$html = $twig->render('ProjectView/' . $template, $vars);
			$check(true, $name);
			if ($name === 'detail.html.twig') {
				$check(str_contains($html, '{{ 7*7 }}') && str_contains($html, 'v-pre'), 'customer text with {{ }} stays literal inside v-pre');
				$check(str_contains($html, 'page=restore&amp;customer=customer1&amp;project=1&amp;scope=web'), 'backup restore links use restoreParams() and override the scope');
				$check(str_contains($html, 'name="confirmation"') && str_contains($html, 'pattern="customer1sql1"'), 'typed delete confirmation');
				$check(!str_contains($html, '%s') && !str_contains($html, '%d'), 'no unreplaced placeholders');
			}
		} catch (\Throwable $e) {
			$check(false, $name . ': ' . $e->getMessage());
		}
	}
}

// Froxlor's own alert box prints the message with |raw: an error message
// containing a customer-chosen site address must stay inert.
$alertbox = $froxlor . '/templates/Froxlor/misc/alertbox.html.twig';
if (is_file($alertbox)) {
	echo "Error page\n";
	require_once __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Html.php';
	$alert = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['a' => file_get_contents($alertbox)]));
	$alert->addFunction(new \Twig\TwigFunction('lng', static fn($k) => 'Back'));
	$html = $alert->render('a', [
		'type' => 'danger', 'btntype' => 'light', 'heading' => 'Error', 'redirect_link' => '', 'alert_info' => '',
		'alert_msg' => \Froxlor\Ext\ProjectView\Html::errorMessage('http://<img src=x onerror=alert(document.cookie)> {{ 7*7 }}'),
	]);
	$check(!str_contains($html, '<img') && !str_contains($html, '{{') && str_contains($html, '<span v-pre>'), 'customer text in an error message is inert in Froxlor\'s alert box');
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
