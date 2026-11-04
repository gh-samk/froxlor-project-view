<?php

/**
 * Applies all Project View migrations.
 *
 *   php migrations/apply.php /var/www/froxlor
 *   php migrations/apply.php /var/www/froxlor --dry-run
 *
 * Connects via PDO with exactly the credentials and connection options
 * Froxlor itself uses (lib/userdata.inc.php, read without executing it — see
 * connection.php). So the installation needs neither a MySQL command line
 * client nor a second configuration, and it works wherever Froxlor runs.
 *
 * Migrations are numbered files, applied in name order:
 * - *.sql is executed statement by statement.
 * - *.php returns a function that receives the connection — for steps that
 *   cannot be written idempotently in plain SQL (adding columns does not
 *   work with IF NOT EXISTS on MySQL).
 *
 * All migrations are idempotent; running them again is harmless. They come
 * from this repository, never from the Froxlor directory.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';

$froxlor = $argv[1] ?? '';
$dryRun = in_array('--dry-run', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = pv_connect($froxlor);
echo "  Database '$db' on '$host' as '$user'\n";

$files = array_merge(glob(__DIR__ . '/[0-9]*.sql') ?: [], glob(__DIR__ . '/[0-9]*.php') ?: []);
sort($files);
if (!$files) {
	echo "  No migrations found.\n";
	exit(0);
}

foreach ($files as $file) {
	$name = basename($file);
	if ($dryRun) {
		echo "  would apply: $name\n";
		continue;
	}

	try {
		if (str_ends_with($file, '.php')) {
			$migration = require $file;
			if (!is_callable($migration)) {
				throw new RuntimeException('does not return a function');
			}
			$migration($pdo);
		} else {
			foreach (pv_sql_statements((string)file_get_contents($file)) as $statement) {
				$pdo->exec($statement);
			}
		}
	} catch (Throwable $e) {
		fwrite(STDERR, "  ERROR in $name:\n    " . $e->getMessage() . "\n");
		exit(1);
	}
	echo "  applied: $name\n";
}

/**
 * Splits an SQL file into single statements.
 *
 * Deliberately simple: the SQL migrations of this extension contain no
 * semicolons inside strings and no stored procedures. They are therefore
 * split at the semicolon after removing comment lines.
 */
function pv_sql_statements(string $content): array
{
	$lines = [];
	foreach (explode("\n", $content) as $line) {
		if (preg_match('/^\s*--/', $line)) {
			continue;
		}
		$lines[] = $line;
	}

	$raw = explode(';', implode("\n", $lines));

	return array_values(array_filter(array_map('trim', $raw), static fn($s) => $s !== ''));
}
