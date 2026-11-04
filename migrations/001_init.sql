-- Project View for Froxlor 2.x — base schema
--
-- Applied by migrations/apply.php (install.sh). All objects carry the prefix
-- "ext_projectview_", so they never collide with Froxlor's tables and remain
-- clearly identifiable in a later migration.

-- Assignment of resources that Froxlor itself cannot attach to a domain:
-- panel_databases and ftp_users only know a customerid, no domainid.
CREATE TABLE IF NOT EXISTS `ext_projectview_resources` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `domainid`      int unsigned NOT NULL COMMENT 'project = panel_domains.id with parentdomainid=0',
  `resource_type` enum('database','ftp') NOT NULL,
  `resource_id`   int NOT NULL COMMENT 'panel_databases.id or ftp_users.id',
  `assigned_at`   int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `one_project_per_resource` (`resource_type`,`resource_id`),
  KEY `domainid` (`domainid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Result of the WordPress scan. A domain can carry several installations
-- (e.g. main site plus a subdirectory), hence one row per path.
CREATE TABLE IF NOT EXISTS `ext_projectview_wp_installs` (
  `id`        int unsigned NOT NULL AUTO_INCREMENT,
  `domainid`  int unsigned NOT NULL,
  `path`      varchar(255) NOT NULL,
  `version`   varchar(32) DEFAULT NULL,
  `last_seen` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dom_path` (`domainid`,`path`),
  KEY `domainid` (`domainid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache for the current WordPress version from api.wordpress.org, so the scan
-- does not call out on every run.
CREATE TABLE IF NOT EXISTS `ext_projectview_wp_latest` (
  `id`         tinyint unsigned NOT NULL DEFAULT 1,
  `version`    varchar(32) DEFAULT NULL,
  `fetched_at` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `ext_projectview_wp_latest` (`id`,`version`,`fetched_at`) VALUES (1, NULL, 0);

-- Own cron job. Froxlor loads cronclass dynamically from this table
-- (lib/Froxlor/Cli/MasterCron.php); there is no registration in code. The
-- description is plain text in desc_lng_key: Text::crondesc passes it through
-- lng(), and unknown keys come back unchanged.
INSERT INTO `cronjobs_run` (`module`,`cronfile`,`cronclass`,`interval`,`isactive`,`desc_lng_key`)
SELECT 'ext/projectview','ext_projectview_wpscan','\\Froxlor\\Ext\\ProjectView\\Cron\\WordpressScanCron','1 DAY',1,
       'Project View: detect WordPress installations' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `cronjobs_run` WHERE `cronfile` = 'ext_projectview_wpscan');
