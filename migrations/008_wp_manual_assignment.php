<?php

/**
 * Manual WordPress assignment: `manual` = 1 means the scan must no longer
 * change the project of this installation by comparing paths.
 *
 * A PHP migration for the same reason as 003: MySQL has no
 * "ADD COLUMN IF NOT EXISTS". Idempotent, may run any number of times.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

return static function (PDO $pdo): void {
	$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
	$stmt->execute(['ext_projectview_wp_installs', 'manual']);
	if ((int)$stmt->fetchColumn() === 0) {
		$pdo->exec('ALTER TABLE `ext_projectview_wp_installs` ADD COLUMN `manual` TINYINT(1) NOT NULL DEFAULT 0');
	}
};
