-- Disk usage per project, computed once a day by the scan and stored here.
-- Computing it live is out of the question: `du` over customer directories
-- needs root and can take a while for large sites — that does not belong in
-- a page request.
CREATE TABLE IF NOT EXISTS `ext_projectview_stats` (
	`domainid` INT(11) UNSIGNED NOT NULL,
	`disk_files_kb` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	`disk_db_kb` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
	`computed_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`domainid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
