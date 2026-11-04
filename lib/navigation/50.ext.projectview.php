<?php

/**
 * Navigation entries of Project View.
 *
 * Froxlor merges all *.php files from lib/navigation/, sorted and recursively
 * (PhpHelper::loadConfigArrayDir, called in lib/init.php). This file is
 * therefore purely additive — no Froxlor file needs to be touched. The "50."
 * makes sure the entry is sorted after the core menu (00.froxlor.main.php).
 *
 * The file runs on every page load. It uses that for the way back:
 * - In the admin area the most recently opened project is listed in the menu,
 *   so you can return with one click from any Froxlor section.
 * - After "log in as customer" the customer area gets an entry that restores
 *   the admin session and jumps straight into the project.
 *
 * Caution: Froxlor's sidebar outputs labels with |raw
 * (templates/Froxlor/sidebar.html.twig). Every value that comes from the
 * database is therefore escaped here.
 */

use Froxlor\CurrentUser;
use Froxlor\Ext\ProjectView\Lang;

$ext_projectview_last = null;
$ext_projectview_return = null;

if (CurrentUser::hasSession()) {
	$value = CurrentUser::getField('ext_projectview_last_project');
	if (is_array($value) && !empty($value['id'])) {
		$ext_projectview_last = $value;
	}

	// When logging in as a customer, Froxlor stores the complete admin
	// session under switched_user — including the most recently opened project.
	$admin = CurrentUser::getField('switched_user');
	if (is_array($admin) && is_array($admin['ext_projectview_last_project'] ?? null)
		&& !empty($admin['ext_projectview_last_project']['id'])) {
		$ext_projectview_return = $admin['ext_projectview_last_project'];
	}
}

$ext_projectview_e = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$ext_projectview_elements = [
	[
		'url' => 'admin_projects.php?page=overview',
		'label' => Lang::t('Project overview'),
		'required_resources' => 'domains',
	],
	[
		'url' => 'admin_projects.php?page=unassigned',
		'label' => Lang::t('Unassigned'),
		'required_resources' => 'domains',
	],
];

if ($ext_projectview_last !== null) {
	$ext_projectview_elements[] = [
		'url' => 'admin_projects.php?page=detail&id=' . (int)$ext_projectview_last['id'],
		'label' => Lang::t('Recent: %s', $ext_projectview_e($ext_projectview_last['name'] ?? '')),
		'required_resources' => 'domains',
	];
}

$ext_projectview_navigation = [
	'admin' => [
		'ext_projectview' => [
			'label' => Lang::t('Projects'),
			'required_resources' => 'customers',
			'icon' => 'fa-solid fa-diagram-project',
			'elements' => $ext_projectview_elements,
		],
	],
];

if ($ext_projectview_return !== null) {
	// target=projects_return: Froxlor's suback redirects to "admin_<target>.php"
	// but allows no further parameters. admin_projects_return.php therefore
	// reads the project from the restored admin session.
	$ext_projectview_navigation['customer'] = [
		'ext_projectview_return' => [
			'label' => Lang::t('Project View'),
			'icon' => 'fa-solid fa-diagram-project',
			'elements' => [
				[
					'url' => 'customer_index.php?action=suback&target=projects_return',
					'label' => Lang::t('Back to %s', $ext_projectview_e($ext_projectview_return['name'] ?? Lang::t('Project'))),
				],
			],
		],
	];
}

return $ext_projectview_navigation;
