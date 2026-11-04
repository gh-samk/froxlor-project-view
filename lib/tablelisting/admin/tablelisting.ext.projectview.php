<?php

/**
 * Column definition of the project overview.
 *
 * Froxlor collects these files with a glob pattern over lib/tablelisting/ and
 * its subdirectories (lib/Froxlor/UI/Listing.php), so the file is purely
 * additive. Following the Froxlor convention, the list gets column
 * selection, sorting and search for free — the selection is stored per user
 * in panel_usercolumns.
 */

use Froxlor\Ext\ProjectView\Callbacks\Project;
use Froxlor\Ext\ProjectView\Lang;
use Froxlor\UI\Listing;

return [
	'project_list' => [
		'title' => Lang::t('Projects'),
		'icon' => 'fa-solid fa-diagram-project',
		'description' => Lang::t('A project is a main domain with everything attached to it.'),
		'self_overview' => ['section' => 'projects', 'page' => 'overview'],
		'default_sorting' => ['d.domain_ace' => 'asc'],
		'empty_msg' => Lang::t('There are no projects yet. As soon as a main domain is created, it appears here.'),
		'columns' => [
			'd.domain_ace' => [
				'label' => Lang::t('Project'),
				'field' => 'domain_ace',
				'callback' => [Project::class, 'name'],
				'isdefaultsearchfield' => true,
			],
			'wordpress' => [
				'label' => 'WordPress',
				'field' => 'id',
				'callback' => [Project::class, 'wordpress'],
				'sortable' => false,
				'searchable' => false,
			],
			'resources' => [
				'label' => Lang::t('Resources'),
				'field' => 'id',
				'callback' => [Project::class, 'resources'],
				'sortable' => false,
				'searchable' => false,
			],
			'storage' => [
				'label' => Lang::t('Disk space'),
				'field' => 'id',
				'callback' => [Project::class, 'storage'],
				'sortable' => false,
				'searchable' => false,
			],
			'd.letsencrypt' => [
				'label' => 'Let\'s Encrypt',
				'field' => 'letsencrypt',
				'callback' => [Project::class, 'ssl'],
				'searchable' => false,
			],
			'd.documentroot' => [
				'label' => Lang::t('Path'),
				'field' => 'documentroot',
				'callback' => [Project::class, 'documentroot'],
			],
		],
		'visible_columns' => Listing::getVisibleColumnsForListing('project_list', [
			'd.domain_ace',
			'wordpress',
			'resources',
			'storage',
			'd.letsencrypt',
		]),
		'actions' => [
			'open' => [
				'icon' => 'fa-solid fa-arrow-right',
				'title' => Lang::t('Open project'),
				'class' => 'btn-outline-primary',
				'href' => [
					'section' => 'projects',
					'page' => 'detail',
					'id' => ':id',
				],
			],
		],
	],
];
