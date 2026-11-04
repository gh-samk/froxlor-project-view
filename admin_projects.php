<?php

/**
 * Project View for Froxlor 2.x
 *
 * A project is a main domain (panel_domains with parentdomainid = 0) with
 * everything attached to it: subdomains, email, databases, FTP, WordPress.
 *
 * This file is purely additive — Froxlor does not know it and therefore does
 * not overwrite it during an update.
 *
 * Write operations go exclusively through the Froxlor commands in
 * lib/Froxlor/Api/Commands/. They encapsulate validation, quota checks,
 * system task creation and logging. Own SQL exists only on the extension's
 * own tables (ext_projectview_*).

 */

const AREA = 'admin';
require __DIR__ . '/lib/init.php';

use Froxlor\Api\Commands\Domains;
use Froxlor\Api\Commands\EmailAccounts;
use Froxlor\Api\Commands\EmailForwarders;
use Froxlor\Api\Commands\Emails;
use Froxlor\Api\Commands\EmailSender;
use Froxlor\Api\Commands\Ftps;
use Froxlor\Api\Commands\Mysqls;
use Froxlor\Api\Commands\SubDomains;
use Froxlor\CurrentUser;
use Froxlor\Ext\ProjectView\Html;
use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\MailPackage;
use Froxlor\Ext\ProjectView\ProjectRepository;
use Froxlor\Ext\ProjectView\ProjectStats;
use Froxlor\Ext\ProjectView\ResourceMapper;
use Froxlor\Ext\ProjectView\SubdomainSsl;
use Froxlor\Ext\ProjectView\WordpressCli;
use Froxlor\Ext\ProjectView\WordpressJobs;
use Froxlor\Ext\ProjectView\WordpressLogin;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;
use Froxlor\UI\Listing;
use Froxlor\UI\Panel\UI;
use Froxlor\UI\Request;
use Froxlor\UI\Response;

/**
 * Renders a template from templates/ProjectView/.
 *
 * Not UI::view(): that prefixes the name with the active theme and would
 * look for "Froxlor/ProjectView/...". The Twig loader is rooted in
 * templates/, so the path here is absolute from there — and the templates
 * still extend "Froxlor/userarea.html.twig" as usual.
 */
function pv_view(string $template, array $context = []): void
{
	// Version marker for style.css: Froxlor sends no Cache-Control; without a
	// changing address browsers would keep the old version after an update.
	$context['style_version'] = (int)@filemtime(__DIR__ . '/templates/ProjectView/style.css');
	try {
		Lang::registerTwig(UI::twig());
		echo UI::twig()->render('ProjectView/' . $template, $context);
	} catch (\Throwable $e) {
		// Template errors can contain paths and data; they go to the PHP error
		// log, the page only says that something went wrong.
		error_log('Project View: rendering ' . $template . ' failed: ' . $e->getMessage());
		pv_error(Lang::t('The page could not be displayed. Details are in the PHP error log.'));
	}
}

/**
 * Shows Froxlor's error page with a message that is safe to display (see
 * Html::errorMessage). Every error on the Project View pages goes through here.
 */
function pv_error(string $message): void
{
	Response::dynamicError(Html::errorMessage($message));
	exit;
}

/**
 * Disk usage of a project for the detail page, numbers already formatted.
 * null if the scan has not measured the project yet.
 */
function pv_storage(int $domainid): ?array
{
	$stat = ProjectStats::forDomain($domainid);
	if ($stat === null) {
		return null;
	}
	$stat['total_readable'] = ProjectStats::humanReadable($stat['total']);
	$stat['files_readable'] = ProjectStats::humanReadable($stat['files']);
	$stat['db_readable'] = ProjectStats::humanReadable($stat['db']);

	return $stat;
}

/**
 * Remembers a notice for the next page load. After POST-redirect-GET a change
 * would otherwise be silent — and people click again.
 */
function pv_flash(string $text, string $type = 'success'): void
{
	CurrentUser::setField('ext_projectview_flash', ['type' => $type, 'text' => $text]);
}

/**
 * Runs a Froxlor command and then returns to the calling view
 * (POST-redirect-GET, so a reload repeats nothing).
 */
function pv_command(callable $fn, array $redirect, ?string $flash = null): void
{
	global $filename;

	try {
		$fn();
	} catch (Exception $e) {
		pv_error($e->getMessage());
	}
	if ($flash !== null) {
		pv_flash($flash);
	}
	Response::redirectTo($filename, $redirect);
}

/**
 * Looks up a resource in the lists of this project.
 *
 * Two purposes: a manipulated ID from another project is caught here before
 * any command runs — and the name for the delete confirmation comes from the
 * database, never from the form.
 */
function pv_find(array $list, int $rid, string $what): array
{
	foreach ($list as $entry) {
		if ((int)$entry['id'] === $rid) {
			return $entry;
		}
	}
	pv_error(Lang::t('%s does not belong to this project.', $what));
	exit;
}

/**
 * Checks the typed confirmation before deleting. Disabling the button in the
 * browser is only a convenience; this check on the server is what counts.
 */
function pv_confirmed(string $expected): void
{
	$input = trim((string)Request::post('confirmation', ''));
	if ($input === '' || mb_strtolower($input) !== mb_strtolower($expected)) {
		pv_error(
			Lang::t('The confirmation does not match "%s". Nothing was deleted.', $expected)
		);
	}
}

/**
 * State of the backup for the Backup box of the project page.
 *
 * The box exists only when the optional Backup extension is installed; it is
 * the only coupling between the two, and it is one-sided: Project View asks
 * via class_exists() and works the same without it. Any failure inside the
 * other extension hides the box instead of breaking the project page.
 *
 * @return array{configured:bool, last_backup:?string, stale:bool, allowed:bool, restore_params:array}|null
 */
function pv_backup(array $project): ?array
{
	if (!class_exists(\Froxlor\Ext\Backup\ProjectViewIntegration::class)) {
		return null;
	}
	try {
		$login = (string)$project['loginname'];
		$status = \Froxlor\Ext\Backup\ProjectViewIntegration::status($login, (array)($GLOBALS['userinfo'] ?? []));
		$last = isset($status['last_backup']) ? (int)$status['last_backup'] : 0;

		return [
			'configured' => !empty($status['configured']),
			// The German catalog appends " Uhr" to the time.
			'last_backup' => $last > 0 ? Lang::t('%s', date('d.m.Y H:i', $last)) : null,
			'stale' => !empty($status['stale']),
			'allowed' => !empty($status['allowed']),
			'restore_params' => (array)\Froxlor\Ext\Backup\ProjectViewIntegration::restoreParams($login, (int)$project['id']),
		];
	} catch (\Throwable $e) {
		return null;
	}
}

function pv_switch(string $field): int
{
	return Request::post($field) === '1' ? 1 : 0;
}

/**
 * Runs $fn as if the customer were logged in — only for the duration of the call.
 *
 * Needed for EmailSender: Froxlor's validateLocalDomainOwnership checks domain
 * ownership against CurrentUser::getField('customerid'), i.e. against the
 * session instead of the customer of the command. An admin session has no
 * customerid, so every domain of the customer would count as foreign
 * ("senderdomainnotowned") — a bug in Froxlor.
 *
 * Restored in catch, not in finally: Froxlor's error page ends PHP with exit,
 * and no finally blocks run after that. The admin session would otherwise
 * keep a foreign customerid for good.
 */
function pv_in_customer_context(int $customerid, callable $fn): void
{
	$had = array_key_exists('customerid', $_SESSION['userinfo'] ?? []);
	$before = $had ? $_SESSION['userinfo']['customerid'] : null;
	$restore = static function () use ($had, $before): void {
		if ($had) {
			$_SESSION['userinfo']['customerid'] = $before;
		} else {
			unset($_SESSION['userinfo']['customerid']);
		}
	};

	// Froxlor's error page ends PHP with exit; a shutdown function restores
	// the session even then. Restoring twice is harmless.
	register_shutdown_function($restore);
	CurrentUser::setField('customerid', $customerid);
	try {
		$fn();
	} catch (\Throwable $e) {
		$restore();
		throw $e;
	}
	$restore();
}

$id = (int)Request::any('id');

/*
 * Overview: all projects of the admin.
 */
if ($page == 'overview' || $page == 'projects') {

	$log->logAction(FroxlorLogger::ADM_ACTION, LOG_NOTICE, 'viewed admin_projects');

	$listing_data = include __DIR__ . '/lib/tablelisting/admin/tablelisting.ext.projectview.php';
	$projects = ProjectRepository::listProjects();

	pv_view('list.html.twig', [
		// Unlike format(), formatFromArray expects the already unwrapped
		// definition, not the wrapper keyed by ID.
		'listing' => Listing::formatFromArray(
			['data' => $projects, 'pagination' => []],
			$listing_data['project_list'],
			'project_list'
		),
		'unassigned_count' => ResourceMapper::countUnassigned(),
	]);

/*
 * Resources without a project.
 *
 * This section never goes away. There will always be databases and accounts
 * that belong to no project — test instances, shared accounts, leftovers. A
 * system that pretends they do not exist loses trust exactly there.
 */
} elseif ($page == 'unassigned') {

	if ($action == 'assign' && Request::post('send') == 'send') {
		$type = (string)Request::post('resource_type');
		$resourceId = (int)Request::post('resource_id');
		$targetDomain = (int)Request::post('domainid');

		// Check the target project against the admin's visibility before
		// writing — otherwise a form could assign a resource to someone
		// else's project.
		$target = ProjectRepository::getProject($targetDomain);
		if ($target === null) {
			pv_error(Lang::t('Unknown or inaccessible project.'));
		}
		// The resource must exist and belong to the project's customer (whose
		// visibility getProject() has just checked). A resource that is
		// already assigned may only be moved away from a project this admin
		// can see.
		if (!ResourceMapper::isValidType($type)
			|| ResourceMapper::resourceCustomer($type, $resourceId) !== (int)$target['customerid']) {
			pv_error(Lang::t('This resource does not belong to the customer of the project.'));
		}
		$current = ResourceMapper::assignedProject($type, $resourceId);
		if ($current !== null && ProjectRepository::getProject($current) === null) {
			pv_error(Lang::t('Unknown or inaccessible project.'));
		}

		try {
			ResourceMapper::assign($type, $resourceId, $targetDomain);
			$log->logAction(
				FroxlorLogger::ADM_ACTION,
				LOG_NOTICE,
				sprintf('Project View: assigned %s #%d to project #%d', $type, $resourceId, $targetDomain)
			);
		} catch (Exception $e) {
			pv_error($e->getMessage());
		}

		Response::redirectTo($filename, ['page' => 'unassigned']);
	}

	pv_view('unassigned.html.twig', [
		'databases' => ResourceMapper::unassignedDatabases(),
		'ftps' => ResourceMapper::unassignedFtps(),
	]);

/*
 * The project page itself.
 */
} elseif ($page == 'detail') {

	$project = ProjectRepository::getProject($id);
	if ($project === null) {
		// Missing, not a main domain or outside this admin's visibility — the
		// three cases are deliberately not distinguished.
		pv_error(Lang::t('Project not found or no access.'));
	}

	$backToProject = ['page' => 'detail', 'id' => $id];
	$customerid = (int)$project['customerid'];
	$domainName = (string)$project['domain'];
	$antispamActive = (int)Settings::Get('antispam.activated') === 1;

	if (Request::post('send') == 'send') {
		switch ($action) {

			/* ---------------------------------------------------- Domain */

			case 'domain_docroot':
				// Unlike Domains::add and SubDomains::update, Domains::update does
				// not resolve relative paths, and a leading "/" counts as absolute
				// for admins with the server settings permission. The full path is
				// therefore built here, always inside the customer directory.
				// Free paths are left to Froxlor's form.
				$rest = trim((string)Request::post('documentroot'), " \t/");
				if (str_contains($rest, '..')) {
					pv_error(Lang::t('The path must not contain "..".'));
				}
				$path = rtrim((string)$project['customer_docroot'], '/') . '/' . ($rest === '' ? '' : $rest . '/');
				pv_command(function () use ($userinfo, $id, $path) {
					Domains::getLocal($userinfo, [
						'id' => $id,
						'documentroot' => $path,
					])->update();
					MailPackage::maintainForDomain($id);
				}, $backToProject, Lang::t('Document path saved. It takes effect on the next cron run.'));
				break;

			case 'ssl_toggle':
				$wanted = empty($project['letsencrypt']) ? 1 : 0;
				pv_command(function () use ($userinfo, $id, $wanted) {
					// Pass only letsencrypt: Domains::update resets every parameter
					// that is not passed to the existing value.
					Domains::getLocal($userinfo, [
						'id' => $id,
						'letsencrypt' => $wanted,
					])->update();
					MailPackage::maintainForDomain($id);
					pv_check_ssl($id, ['letsencrypt' => $wanted]);
				}, $backToProject, $wanted ? Lang::t('Let\'s Encrypt turned on.') : Lang::t('Let\'s Encrypt turned off.'));
				break;

			case 'domain_ssl':
				$ssl = [
					'sslenabled' => pv_switch('sslenabled'),
					'letsencrypt' => pv_switch('letsencrypt'),
					'ssl_redirect' => pv_switch('ssl_redirect'),
					'http2' => pv_switch('http2'),
					'hsts_maxage' => max(0, (int)Request::post('hsts_maxage', 0)),
				];
				// Server alias: 0 = wildcard, 1 = www, 2 = none. Let's Encrypt
				// only works in Froxlor with www or no alias.
				$alias = (string)Request::post('selectserveralias', '');
				if (in_array($alias, ['0', '1', '2'], true)) {
					$ssl['selectserveralias'] = (int)$alias;
				}
				// Without SSL there is no certificate, no redirect and no
				// HTTP/2 either — Froxlor resets that anyway.
				if (!$ssl['sslenabled']) {
					$ssl['letsencrypt'] = $ssl['ssl_redirect'] = $ssl['http2'] = 0;
				}
				pv_command(function () use ($userinfo, $id, $ssl) {
					Domains::getLocal($userinfo, array_merge(['id' => $id], $ssl))->update();
					MailPackage::maintainForDomain($id);
					pv_check_ssl($id, ['ssl_enabled' => $ssl['sslenabled'], 'letsencrypt' => $ssl['letsencrypt']]);
				}, $backToProject, Lang::t('SSL settings saved. They take effect on the next cron run.'));
				break;

			/* ------------------------------------------------ Subdomains */

			case 'subdomain_add':
				pv_command(function () use ($userinfo, $customerid, $domainName) {
					SubDomains::getLocal($userinfo, [
						'customerid' => $customerid,
						'subdomain' => trim((string)Request::post('subdomain')),
						'domain' => $domainName,
						'path' => trim((string)Request::post('path', '')),
					])->add();
				}, $backToProject, Lang::t('Subdomain created. It becomes active on the next cron run.'));
				break;

			case 'subdomain_update':
				$sub = pv_find(ProjectRepository::getSubdomains($id), (int)Request::post('subid'), Lang::t('This subdomain'));
				$target = trim((string)Request::post('target'));
				$params = [
					'customerid' => $customerid,
					'id' => (int)$sub['id'],
					'letsencrypt' => pv_switch('letsencrypt'),
					'ssl_redirect' => pv_switch('ssl_redirect'),
				];
				$alias = (string)Request::post('selectserveralias', '');
				if (in_array($alias, ['0', '1', '2'], true)) {
					$params['selectserveralias'] = (int)$alias;
				}
				// A target with http(s):// is a redirect, anything else a
				// directory — the same distinction Froxlor makes.
				if (ProjectRepository::isUrl($target)) {
					$params['url'] = $target;
				} else {
					$params['url'] = '';
					$params['path'] = $target;
				}
				pv_command(function () use ($userinfo, $params) {
					SubDomains::getLocal($userinfo, $params)->update();
				}, $backToProject, Lang::t('Subdomain %s saved. The change takes effect on the next cron run.', $sub['domain']));
				break;

			case 'subdomain_delete':
				$sub = pv_find(ProjectRepository::getSubdomains($id), (int)Request::post('subid'), Lang::t('This subdomain'));
				pv_confirmed((string)$sub['domain']);
				pv_command(function () use ($userinfo, $customerid, $sub) {
					SubDomains::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$sub['id'],
					])->delete();
				}, $backToProject, Lang::t('Subdomain %s deleted.', $sub['domain']));
				break;

			/* ----------------------------------------------------- Email */

			case 'email_add':
				pv_command(function () use ($userinfo, $customerid, $domainName) {
					$result = Emails::getLocal($userinfo, [
						'customerid' => $customerid,
						'email_part' => trim((string)Request::post('email_part')),
						'domain' => $domainName,
						'description' => Request::post('description', ''),
					])->add();

					// With a password, add the mailbox right away — two steps
					// that are almost always done one after the other.
					$password = (string)Request::post('email_password', '');
					$newId = json_decode($result, true)['data']['id'] ?? null;
					if ($password !== '' && $newId) {
						EmailAccounts::getLocal($userinfo, [
							'customerid' => $customerid,
							'id' => (int)$newId,
							'email_password' => $password,
						])->add();
					}
				}, $backToProject, Lang::t('Email address created.'));
				break;

			case 'email_account_add':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailAccounts::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'email_password' => Request::post('email_password'),
					])->add();
				}, $backToProject, Lang::t('Mailbox set up for %s.', $mail['email_full']));
				break;

			case 'email_password':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailAccounts::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'email_password' => Request::post('email_password'),
					])->update();
				}, $backToProject, Lang::t('Password for %s changed.', $mail['email_full']));
				break;

			case 'email_quota':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailAccounts::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'email_quota' => max(0, (int)Request::post('email_quota', 0)),
					])->update();
				}, $backToProject, Lang::t('Disk space for %s changed.', $mail['email_full']));
				break;

			case 'email_update':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				$params = [
					'customerid' => $customerid,
					'id' => (int)$mail['id'],
					'iscatchall' => pv_switch('iscatchall'),
					'description' => (string)Request::post('description', ''),
				];
				if ($antispamActive) {
					$params += [
						'spam_tag_level' => (string)Request::post('spam_tag_level', $mail['spam_tag_level']),
						'spam_kill_level' => (string)Request::post('spam_kill_level', $mail['spam_kill_level']),
						'rewrite_subject' => pv_switch('rewrite_subject'),
						'bypass_spam' => pv_switch('bypass_spam'),
						'policy_greylist' => pv_switch('policy_greylist'),
					];
				}
				pv_command(function () use ($userinfo, $params) {
					Emails::getLocal($userinfo, $params)->update();
				}, $backToProject, Lang::t('Settings for %s saved.', $mail['email_full']));
				break;

			case 'email_forward_add':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailForwarders::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'destination' => trim((string)Request::post('destination')),
					])->add();
				}, $backToProject, Lang::t('Forwarder added.'));
				break;

			case 'email_forward_delete':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailForwarders::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'forwarderid' => (int)Request::post('forwarderid'),
					])->delete();
				}, $backToProject, Lang::t('Forwarder removed.'));
				break;

			case 'email_sender_add':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					pv_in_customer_context($customerid, function () use ($userinfo, $customerid, $mail) {
						EmailSender::getLocal($userinfo, [
							'customerid' => $customerid,
							'id' => (int)$mail['id'],
							'allowed_sender' => trim((string)Request::post('allowed_sender')),
						])->add();
					});
				}, $backToProject, Lang::t('Sender alias added.'));
				break;

			case 'email_sender_delete':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_command(function () use ($userinfo, $customerid, $mail) {
					pv_in_customer_context($customerid, function () use ($userinfo, $customerid, $mail) {
						EmailSender::getLocal($userinfo, [
							'customerid' => $customerid,
							'id' => (int)$mail['id'],
							'senderid' => (int)Request::post('senderid'),
						])->delete();
					});
				}, $backToProject, Lang::t('Sender alias removed.'));
				break;

			case 'email_account_delete':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_confirmed((string)$mail['email_full']);
				pv_command(function () use ($userinfo, $customerid, $mail) {
					EmailAccounts::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'delete_userfiles' => pv_switch('delete_userfiles'),
					])->delete();
				}, $backToProject, Lang::t('Mailbox of %s deleted. The address remains.', $mail['email_full']));
				break;

			case 'email_delete':
				$mail = pv_find(ProjectRepository::getEmails($id), (int)Request::post('emailid'), Lang::t('This address'));
				pv_confirmed((string)$mail['email_full']);
				pv_command(function () use ($userinfo, $customerid, $mail) {
					Emails::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$mail['id'],
						'delete_userfiles' => pv_switch('delete_userfiles'),
					])->delete();
				}, $backToProject, Lang::t('Email address %s deleted.', $mail['email_full']));
				break;

			/* ------------------------------------------------- Databases */

			case 'db_add':
				pv_command(function () use ($userinfo, $customerid, $id) {
					$result = Mysqls::getLocal($userinfo, [
						'customerid' => $customerid,
						'mysql_password' => Request::post('mysql_password'),
						'description' => Request::post('description', ''),
					])->add();

					// Assign it to the project right away: a database created from
					// the project page obviously belongs to it.
					$data = json_decode($result, true);
					if (!empty($data['data']['id'])) {
						ResourceMapper::assign(
							ResourceMapper::TYPE_DATABASE,
							(int)$data['data']['id'],
							$id
						);
					}
				}, $backToProject, Lang::t('Database created and assigned to the project.'));
				break;

			case 'db_update':
				$db = pv_find(ProjectRepository::getDatabases($id), (int)Request::post('dbid'), Lang::t('This database'));
				$params = [
					'customerid' => $customerid,
					'id' => (int)$db['id'],
					'description' => (string)Request::post('description', ''),
				];
				// An empty password field means: the password stays as it is.
				$password = (string)Request::post('mysql_password', '');
				if ($password !== '') {
					$params['mysql_password'] = $password;
				}
				pv_command(function () use ($userinfo, $params) {
					Mysqls::getLocal($userinfo, $params)->update();
				}, $backToProject, Lang::t('Database %s saved.', $db['databasename']));
				break;

			case 'db_delete':
				$db = pv_find(ProjectRepository::getDatabases($id), (int)Request::post('dbid'), Lang::t('This database'));
				pv_confirmed((string)$db['databasename']);
				pv_command(function () use ($userinfo, $customerid, $db, $id) {
					Mysqls::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$db['id'],
					])->delete();
					ResourceMapper::unassign(ResourceMapper::TYPE_DATABASE, (int)$db['id'], $id);
				}, $backToProject, Lang::t('Database %s deleted.', $db['databasename']));
				break;

			case 'unassign':
				$type = (string)Request::post('resource_type');
				$resourceId = (int)Request::post('resource_id');
				try {
					ResourceMapper::unassign($type, $resourceId, $id);
				} catch (Exception $e) {
					pv_error($e->getMessage());
				}
				pv_flash(Lang::t('Removed from the project. The resource itself still exists and is listed under "Unassigned".'));
				Response::redirectTo($filename, $backToProject);
				break;

			/* ------------------------------------------------- WordPress */

			case 'wp_job':
				$wp = pv_find(ProjectRepository::getWordpress($id), (int)Request::post('installid'), Lang::t('This WordPress installation'));
				$type = (string)Request::post('type');
				if (!WordpressJobs::isType($type)) {
					pv_error(Lang::t('Unknown job.'));
				}
				$queued = WordpressJobs::enqueue((int)$wp['id'], $id, $type, (string)$userinfo['loginname']);
				$log->logAction(FroxlorLogger::ADM_ACTION, LOG_NOTICE, sprintf('Project View: queued WordPress job "%s" for %s', $type, str_replace(["\r", "\n"], ' ', (string)$wp['path'])));
				pv_flash(
					$queued
						? Lang::t('"%s" has been queued and runs in the background within a minute. Reload the page to see the result.', Lang::t(WordpressJobs::TYPES[$type]))
						: Lang::t('"%s" is already waiting to run for this installation.', Lang::t(WordpressJobs::TYPES[$type])),
					$queued ? 'success' : 'warning'
				);
				Response::redirectTo($filename, $backToProject);
				break;

			case 'wp_login':
				$wp = pv_find(ProjectRepository::getWordpress($id), (int)Request::post('installid'), Lang::t('This WordPress installation'));
				$user = (string)Request::post('user');
				$secret = ProjectRepository::wordpressLoginKey((int)$wp['id']);
				// Only administrators found by the last check — never a freely
				// entered user name.
				if ($secret === null || !in_array($user, $wp['admin_users'], true)) {
					pv_error(Lang::t('Autologin is not set up for this user on this installation.'));
				}
				try {
					$allowedHosts = ProjectRepository::customerHostsForDomain($id);
					$url = WordpressLogin::link($wp, $secret, $user, $allowedHosts);
				} catch (Exception $e) {
					pv_error($e->getMessage());
				}
				// Make field values single-line: siteurl comes from the customer's
				// database, a line break in it would otherwise append a forged
				// line to the log.
				$log->logAction(FroxlorLogger::ADM_ACTION, LOG_WARNING, sprintf(
					'Project View: WordPress autologin to %s as %s',
					str_replace(["\r", "\n"], ' ', (string)$wp['siteurl']),
					str_replace(["\r", "\n"], ' ', $user)
				));
				header('Location: ' . $url);
				exit;

			/* ------------------------------------------------------- FTP */

			case 'ftp_add':
				pv_command(function () use ($userinfo, $customerid, $project, $id) {
					// Ftps::add() expects the path relative to the customer
					// directory and prepends it itself; the absolute documentroot
					// would yield the path twice. Redirect or path outside: the
					// customer directory.
					$result = Ftps::getLocal($userinfo, [
						'customerid' => $customerid,
						'path' => Request::post('path', '/' . ($project['docroot_rest'] ?? '')),
						'ftp_password' => Request::post('ftp_password'),
						'ftp_description' => Request::post('description', ''),
					])->add();

					$data = json_decode($result, true);
					if (!empty($data['data']['id'])) {
						ResourceMapper::assign(
							ResourceMapper::TYPE_FTP,
							(int)$data['data']['id'],
							$id
						);
					}
				}, $backToProject, Lang::t('FTP account created and assigned to the project.'));
				break;

			case 'ftp_password':
				$ftp = pv_find(ProjectRepository::getFtps($id), (int)Request::post('ftpid'), Lang::t('This FTP account'));
				pv_command(function () use ($userinfo, $customerid, $ftp) {
					Ftps::getLocal($userinfo, [
						'customerid' => $customerid,
						'id' => (int)$ftp['id'],
						'ftp_password' => Request::post('ftp_password'),
					])->update();
				}, $backToProject, Lang::t('Password for %s changed.', $ftp['username']));
				break;
		}
	}

	// For the way back from other Froxlor sections and from the customer area
	// (see lib/navigation/50.ext.projectview.php).
	CurrentUser::setField('ext_projectview_last_project', [
		'id' => $id,
		'name' => (string)($project['domain_ace'] ?: $project['domain']),
	]);

	$flash = CurrentUser::getField('ext_projectview_flash');
	CurrentUser::setField('ext_projectview_flash', null);

	$subdomains = ProjectRepository::getSubdomains($id);

	pv_view('detail.html.twig', [
		'project' => $project,
		'stats' => ProjectRepository::aggregateFor($id),
		'subdomains' => $subdomains,
		'ssl_auto' => SubdomainSsl::statusFor(array_column($subdomains, 'id')),
		'ssl_auto_labels' => SubdomainSsl::STATUS,
		'mail_package' => pv_mail_package($project),
		'emails' => ProjectRepository::getEmails($id),
		'databases' => ProjectRepository::getDatabases($id),
		'ftps' => ProjectRepository::getFtps($id),
		'wordpress' => ProjectRepository::getWordpress($id),
		'wp_types' => WordpressJobs::TYPES,
		'storage' => pv_storage($id),
		'backup' => pv_backup($project),
		'flash' => is_array($flash) ? $flash : null,
		'opt' => [
			'use_ssl' => (int)Settings::Get('system.use_ssl') === 1,
			'may_absolute' => (int)($userinfo['change_serversettings'] ?? 0) === 1,
			'antispam' => $antispamActive,
			'quota' => (int)Settings::Get('system.mail_quota_enabled') === 1,
			'senders' => (int)Settings::Get('mail.enable_allow_sender') === 1,
			'webmail_url' => (string)Settings::Get('panel.webmail_url'),
			'phpmyadmin_url' => (string)Settings::Get('panel.phpmyadmin_url'),
			'adminer_url' => (string)Settings::Get('ext_projectview.adminer_url'),
			'webftp_url' => (string)Settings::Get('panel.webftp_url'),
			'wpcli' => WordpressCli::wpcliPath() !== null,
		],
	]);

} else {
	Response::redirectTo($filename, ['page' => 'overview']);
}

/**
 * Mail package of the domain for the email box: one row per kind, with the
 * state from ext_projectview_mail_package. null if it does not apply to the domain.
 */
function pv_mail_package(array $project): ?array
{
	$mode = MailPackage::mode();
	// The same conditions as in MailPackage's domain selection, otherwise
	// "Not checked yet" would show here forever.
	if ($mode === 'off' || (int)$project['isemaildomain'] !== 1 || (int)$project['email_only'] === 1
		|| (int)$project['deactivated'] === 1 || !empty($project['aliasdomain'])) {
		return null;
	}
	$status = MailPackage::statusFor((int)$project['id']);
	$rows = [];
	foreach (MailPackage::KINDS as $kind => $purpose) {
		$entry = $status[$kind] ?? null;
		$rows[] = [
			'kind' => $kind,
			'name' => $kind . '.' . ($project['domain_ace'] ?: $project['domain']),
			'purpose' => Lang::t($purpose),
			'status' => $entry['status'] ?? 'pending',
			'text' => isset(MailPackage::STATUS[$entry['status'] ?? '']) ? Lang::t(MailPackage::STATUS[$entry['status']]) : Lang::t('Not checked yet'),
			'message' => (string)($entry['message'] ?? ''),
		];
	}

	return [
		'mode' => $mode,
		'rows' => $rows,
		'waiting_dns' => in_array('waiting_dns', array_column($rows, 'status'), true),
		'mail_server' => 'mail.' . ($project['domain_ace'] ?: $project['domain']),
	];
}

/**
 * Froxlor silently resets SSL values when SSL is off system-wide or the
 * domain has no SSL-capable IP assigned — without an error message. Instead
 * of re-implementing those rules, the result is checked: a switch that
 * silently does nothing is worse than a clear refusal.
 */
function pv_check_ssl(int $id, array $expected): void
{
	$after = ProjectRepository::getProject($id);
	if ($after === null) {
		return;
	}
	foreach ($expected as $field => $value) {
		if ((int)$value === 1 && (int)$after[$field] !== 1) {
			pv_error(
				Lang::t('The SSL setting could not be applied. Froxlor only allows SSL and Let\'s Encrypt if SSL is enabled system-wide (Settings › SSL) and the domain has an SSL-capable IP address assigned.')
			);
		}
	}
}
