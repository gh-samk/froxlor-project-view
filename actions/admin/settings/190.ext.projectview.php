<?php

/**
 * Settings of Project View.
 *
 * Froxlor merges all *.php files from actions/admin/settings/
 * (admin_settings.php, PhpHelper::loadConfigArrayDir). This file is therefore
 * purely additive and appears as a group of its own under Settings.
 *
 * Froxlor does not know Adminer — unlike phpMyAdmin (panel.phpmyadmin_url)
 * and Roundcube (panel.webmail_url), which Froxlor keeps as settings itself
 * and which are therefore not duplicated here.
 */

use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\MailSni;

// Froxlor's own per-domain mail SNI (mail.sni_enabled, expected in a future
// release) replaces Project View's SNI files. MailSni then stands down; say so
// next to the mail package setting.
$ext_projectview_mail_note = Lang::t('Creates the subdomains mail., webmail., autoconfig. and autodiscover. with Let\'s Encrypt for every email domain. mail. gets its own certificate for IMAP, POP3 and SMTP, webmail. shows Roundcube, and the other two set up Thunderbird and Outlook automatically. In dry run mode, the Project View only shows what would be created. The customer domain\'s DNS needs a wildcard record (*) pointing to this server. The four subdomains count towards the customer\'s subdomain quota; if it is not enough, nothing is created. "Off" creates nothing new but maintains what already exists.');
if (MailSni::nativeSniActive()) {
	$ext_projectview_mail_note .= ' ' . Lang::t('Froxlor\'s own mail SNI (mail.sni_enabled) is active: Project View does not write its own Postfix and Dovecot SNI files and has removed them. The subdomains are still created.');
}

return [
	'groups' => [
		'ext_projectview' => [
			'title' => Lang::t('Project View'),
			'icon' => 'fa-solid fa-diagram-project',
			'fields' => [
				'ext_projectview_adminer_url' => [
					'label' => [
						'title' => Lang::t('Adminer URL'),
						'description' => Lang::t('Address of an Adminer installation, e.g. https://server.example/adminer/. Leave empty if no Adminer is available — the Project View then shows no Adminer button. User and database are prefilled when it opens, the password is not.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'adminer_url',
					'type' => 'url',
					'string_emptyallowed' => true,
					'default' => '',
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_wpcli_path' => [
					'label' => [
						'title' => Lang::t('Path to WP-CLI'),
						'description' => Lang::t('Executable of WP-CLI, e.g. /usr/local/bin/wp. Leave empty to search in /usr/local/bin/wp, /usr/bin/wp and /opt/wp-cli/wp. Without WP-CLI, the Project View has no autologin and no updates.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'wpcli_path',
					'type' => 'text',
					'string_type' => 'file',
					'string_emptyallowed' => true,
					'default' => '',
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_wp_backup_dir' => [
					'label' => [
						'title' => Lang::t('Directory for WordPress backups'),
						'description' => Lang::t('Before every update, the installation\'s database and files are stored here. Only root has access — the backups contain database passwords. Do not create it inside the customer directories.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'wp_backup_dir',
					'type' => 'text',
					'string_type' => 'dir',
					'default' => '/var/backups/froxlor-project-view/',
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_wp_backup_keep' => [
					'label' => [
						'title' => Lang::t('Backups kept per installation'),
						'description' => Lang::t('Older backups are deleted after each new one.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'wp_backup_keep',
					'type' => 'number',
					'min' => 1,
					'max' => 30,
					'default' => 3,
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_ssl_subdomains_auto' => [
					'label' => [
						'title' => Lang::t('Subdomains automatically with Let\'s Encrypt'),
						'description' => Lang::t('Subdomains without their own certificate get one if their main domain has one and the DNS record points to this server — new and existing ones alike. If the main domain has an HTTPS redirect, the subdomain gets one too. Each subdomain is switched only once: if you turn Let\'s Encrypt off afterwards, it stays off.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'ssl_subdomains_auto',
					'type' => 'checkbox',
					'default' => true,
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_mail_package_mode' => [
					'label' => [
						'title' => Lang::t('Mail package for email domains'),
						'description' => $ext_projectview_mail_note,
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'mail_package_mode',
					'type' => 'select',
					'default' => 'dry_run',
					'select_var' => [
						'off' => Lang::t('Off'),
						'dry_run' => Lang::t('Dry run (create nothing)'),
						'on' => Lang::t('On'),
					],
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_mail_package_roundcube_dir' => [
					'label' => [
						'title' => Lang::t('Roundcube directory for webmail.'),
						'description' => Lang::t('Public directory of the Roundcube installation. With the Debian package: /var/lib/roundcube/public_html.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'mail_package_roundcube_dir',
					'type' => 'text',
					'string_type' => 'dir',
					'default' => '/var/lib/roundcube/public_html/',
					'save_method' => 'storeSettingField',
				],
				'ext_projectview_mail_package_php_socket' => [
					'label' => [
						'title' => Lang::t('PHP-FPM socket for Roundcube'),
						'description' => Lang::t('Socket of the PHP-FPM pool Roundcube runs in, e.g. /run/php/webapps.sock. The autoconfiguration for Thunderbird and Outlook runs through it as well. Without a socket, the mail package only creates mail.'),
					],
					'settinggroup' => 'ext_projectview',
					'varname' => 'mail_package_php_socket',
					'type' => 'text',
					'string_type' => 'file',
					'string_emptyallowed' => true,
					'default' => '',
					'save_method' => 'storeSettingField',
				],
			],
		],
	],
];
