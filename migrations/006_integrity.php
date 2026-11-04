<?php

/**
 * Integrity check of the WordPress installations: result of
 * `wp core verify-checksums`, `wp plugin verify-checksums --all` and the
 * search for PHP files below wp-content/uploads.
 *
 * A PHP migration for the same reason as 003: MySQL has no
 * "ADD COLUMN IF NOT EXISTS". Idempotent, may run any number of times.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

return static function (PDO $pdo): void {
	$hasColumn = static function (string $table, string $column) use ($pdo): bool {
		$stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
		$stmt->execute([$table, $column]);
		return (int)$stmt->fetchColumn() > 0;
	};

	// The counts are separate columns, so suspicious installations can be
	// found with SQL; the shortened path list and what could not be verified
	// are stored as JSON in integrity_details.
	$columns = [
		'integrity_checked_at' => 'INT NOT NULL DEFAULT 0',
		'integrity_modified' => 'INT NOT NULL DEFAULT 0',
		'integrity_added' => 'INT NOT NULL DEFAULT 0',
		'integrity_missing' => 'INT NOT NULL DEFAULT 0',
		'integrity_uploads' => 'INT NOT NULL DEFAULT 0',
		'integrity_details' => 'TEXT NULL',
	];
	foreach ($columns as $name => $definition) {
		if (!$hasColumn('ext_projectview_wp_installs', $name)) {
			$pdo->exec('ALTER TABLE `ext_projectview_wp_installs` ADD COLUMN `' . $name . '` ' . $definition);
		}
	}
};
