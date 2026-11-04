<?php

/**
 * Way back from the customer area into the most recently opened project.
 *
 * Froxlor's "suback" (back to admin) redirects to admin_<target>.php and
 * allows no further parameters. This page is therefore the target: it reads
 * the project from the admin session that has just been restored and jumps
 * there. Without a remembered project it goes to the overview.
 */

const AREA = 'admin';
require __DIR__ . '/lib/init.php';

use Froxlor\CurrentUser;
use Froxlor\UI\Response;

$last = CurrentUser::getField('ext_projectview_last_project');

if (is_array($last) && !empty($last['id'])) {
	Response::redirectTo('admin_projects.php', ['page' => 'detail', 'id' => (int)$last['id']]);
}

Response::redirectTo('admin_projects.php', ['page' => 'overview']);
