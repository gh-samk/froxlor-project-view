<?php
/**
 * Checks the file-system logic of the WordPress scanner — without a database.
 * Usage:  php tests/wordpress-scanner.php [scratch directory]
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

use Froxlor\Ext\ProjectView\WordpressScanner;

require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/WordpressScanner.php';

function create_tree(string $base): void
{
	// Clean up an old run so symlinks are not created twice.
	if (is_dir($base)) {
		exec('rm -rf ' . escapeshellarg($base));
	}
	mkdir($base . '/customer1/wp-includes', 0755, true);
	touch($base . '/customer1/wp-config.php');
	file_put_contents($base . '/customer1/wp-includes/version.php', "<?php\n\$wp_version = '6.4.2';\n");
	// Decoy inside the installation: must not produce a second find
	mkdir($base . '/customer1/wp-content/plugins', 0755, true);
	touch($base . '/customer1/wp-content/wp-config.php');

	mkdir($base . '/customer2/blog/wp-includes', 0755, true);
	touch($base . '/customer2/blog/wp-config.php');
	file_put_contents($base . '/customer2/blog/wp-includes/version.php', "<?php\n\$wp_version = \"5.9\";\n");

	mkdir($base . '/customer3', 0755, true);
	touch($base . '/customer3/wp-config.php');          // without version.php

	mkdir($base . '/customer4/htdocs', 0755, true);     // no WordPress

	mkdir($base . '/customer5/a/b/c', 0755, true);      // too deep
	touch($base . '/customer5/a/b/c/wp-config.php');

	symlink($base . '/customer1', $base . '/customer6'); // must not be followed

	// version.php as a symbolic link to a file outside: not read
	mkdir($base . '/customer7/wp-includes', 0755, true);
	touch($base . '/customer7/wp-config.php');
	file_put_contents($base . '/secret.php', "<?php\n\$wp_version = '9.9.9';\n");
	symlink($base . '/secret.php', $base . '/customer7/wp-includes/version.php');

	// wp-includes as a symbolic link: not read
	mkdir($base . '/customer8', 0755, true);
	touch($base . '/customer8/wp-config.php');
	symlink($base . '/customer1/wp-includes', $base . '/customer8/wp-includes');

	// version.php as a FIFO: must not block, not read
	mkdir($base . '/customer9/wp-includes', 0755, true);
	touch($base . '/customer9/wp-config.php');
	if (function_exists('posix_mkfifo')) {
		posix_mkfifo($base . '/customer9/wp-includes/version.php', 0644);
	} else {
		exec('mkfifo ' . escapeshellarg($base . '/customer9/wp-includes/version.php'));
	}
}

$base = $argv[1] ?? sys_get_temp_dir() . '/froxlor-project-view-wptest';
create_tree($base);
$expected = [
	'customer1' => ['found' => 1, 'version' => '6.4.2'],
	'customer2' => ['found' => 1, 'version' => '5.9'],
	'customer3' => ['found' => 1, 'version' => null],
	'customer4' => ['found' => 0, 'version' => null],
	'customer5' => ['found' => 0, 'version' => null],
	'customer6' => ['found' => 0, 'version' => null],
	'customer7' => ['found' => 1, 'version' => null],
	'customer8' => ['found' => 1, 'version' => null],
	'customer9' => ['found' => 1, 'version' => null],
];
$failed = 0;
foreach ($expected as $dir => $want) {
	$matches = WordpressScanner::findInstalls($base . '/' . $dir);
	$actual = ['found' => count($matches), 'version' => count($matches) ? reset($matches) : null];
	$ok = ($actual['found'] === $want['found']) && ($actual['version'] === $want['version']);
	printf("  %s %-9s found=%d version=%-8s (expected: %d / %s)\n",
		$ok ? 'OK    ' : 'FAILED', $dir, $actual['found'], var_export($actual['version'], true),
		$want['found'], var_export($want['version'], true));
	if (!$ok) { $failed++; }
}

$start = microtime(true);
WordpressScanner::readVersion($base . '/customer9');
$ok = microtime(true) - $start < 2;
printf("  %s FIFO as version.php does not block\n", $ok ? 'OK    ' : 'FAILED');
if (!$ok) { $failed++; }

// Document root below the customer directory: no component may be a link.
mkdir($base . '/home/web/site', 0755, true);
symlink($base . '/customer1', $base . '/home/linked');
mkdir($base . '/home/real/sub', 0755, true);
symlink($base . '/home/real', $base . '/home/via');
$paths = [
	[$base . '/home', $base . '/home/web/site', true, 'plain directories'],
	[$base . '/home', $base . '/home', true, 'the base itself'],
	[$base . '/home', $base . '/home/linked', false, 'document root is a link'],
	[$base . '/home', $base . '/home/via/sub', false, 'intermediate component is a link'],
	[$base . '/home', $base . '/home/missing', false, 'does not exist'],
	[$base . '/home', $base . '/other', false, 'outside the base'],
	[$base . '/home', $base . '/home/web/../web', false, 'dot-dot component'],
];
foreach ($paths as [$b, $p, $want, $text]) {
	$ok = WordpressScanner::pathHasNoSymlinks($b, $p) === $want;
	printf("  %s pathHasNoSymlinks: %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) { $failed++; }
}

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\n$failed failed\n" : "\nAll passed.\n";
exit($failed ? 1 : 0);
