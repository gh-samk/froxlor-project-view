<?php
/**
 * Checks the integrity check without a database and without WP-CLI: parsing of
 * the verify-checksums output (texts as printed by WP-CLI 2.12) and the
 * uploads scan including symlinks.
 * Usage:  php tests/wordpress-integrity.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\WordpressIntegrity;

$file = __DIR__ . '/../lib/Froxlor/Ext/ProjectView/WordpressIntegrity.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Lang.php';
require $file;
// Without a session English applies; the checks compare English texts.
Lang::set('en');

$failed = 0;
function check(string $what, $actual, $expected): void
{
	global $failed;
	$ok = $actual === $expected;
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $what);
	if (!$ok) {
		printf("         actual:   %s\n         expected: %s\n", json_encode($actual, JSON_UNESCAPED_UNICODE), json_encode($expected, JSON_UNESCAPED_UNICODE));
		$failed++;
	}
}

echo "--- Strictly read-only\n";
// The uploads scan runs as root over customer directories: no function that
// opens, includes or executes a file may appear in the class.
$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
check('no include/require/fopen/file_get_contents/exec in the code',
	preg_match('/\b(include|include_once|require|require_once|fopen|file_get_contents|readfile|file|exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', $code), 0);

echo "--- wp core verify-checksums\n";
$c = WordpressIntegrity::parseCore(0, 'Success: WordPress installation verifies against checksums.', '');
check('clean: no findings, no note', [$c['modified'], $c['added'], $c['missing'], $c['note']], [[], [], [], null]);

$c = WordpressIntegrity::parseCore(1, '', implode("\n", [
	"Warning: File doesn't verify against checksum: wp-includes/version.php",
	"Warning: File doesn't exist: wp-includes/class-wp-hook.php",
	'Warning: File should not exist: wp-includes/evil.php',
	"Error: WordPress installation doesn't verify against checksums.",
]));
check('modified', $c['modified'], ['wp-includes/version.php']);
check('missing', $c['missing'], ['wp-includes/class-wp-hook.php']);
check('added', $c['added'], ['wp-includes/evil.php']);
check('  → with findings no "not verifiable"', $c['note'], null);

// "File should not exist" does not set an error code in WP-CLI.
$c = WordpressIntegrity::parseCore(0, 'Success: WordPress installation verifies against checksums.', 'Warning: File should not exist: wp-admin/x.php');
check('added file despite return value 0', $c['added'], ['wp-admin/x.php']);

$c = WordpressIntegrity::parseCore(1, '', "Error: Couldn't get checksums from WordPress.org.");
check('without connection: not verifiable with reason', $c['note'], "Couldn't get checksums from WordPress.org.");
check('  → no invented findings', count($c['modified']) + count($c['added']) + count($c['missing']), 0);
check('time limit', WordpressIntegrity::parseCore(124, '', '')['note'], 'Time limit exceeded.');
check('return value without message', WordpressIntegrity::parseCore(3, '', '')['note'], 'Return value 3');

$c = WordpressIntegrity::parseCore(1, '', "Warning: File should not exist: wp-includes/a\x1b[31mb.php");
check('control characters in the file name neutralised', $c['added'], ['wp-includes/a?[31mb.php']);

echo "--- wp plugin verify-checksums --all\n";
$p = WordpressIntegrity::parsePlugins(0, 'Success: Verified 2 of 2 plugins.', '');
check('clean: 2 of 2', [$p['checked'], $p['total'], $p['note'], $p['unverifiable']], [2, 2, null, []]);

$skip = "Warning: Couldn't fetch response from https://downloads.wordpress.org/plugin-checksums/premium-x/1.2.3.json (HTTP code 404).\n"
	. "Warning: Could not retrieve the checksums for version 1.2.3 of plugin premium-x, skipping.";
$p = WordpressIntegrity::parsePlugins(1,
	'[{"plugin_name":"akismet","file":"extra.php","message":"File was added"},{"plugin_name":"akismet","file":"akismet.php","message":"Checksum does not match"},{"plugin_name":"akismet","file":"x.php","message":"File is missing"}]',
	$skip . "\nError: Only verified 1 of 3 plugins (1 failed, 1 skipped).");
check('added', $p['added'], ['wp-content/plugins/akismet/extra.php']);
check('modified', $p['modified'], ['wp-content/plugins/akismet/akismet.php']);
check('missing', $p['missing'], ['wp-content/plugins/akismet/x.php']);
check('premium plugin not verifiable, no finding', $p['unverifiable'], ['premium-x']);
check('  → tally 1 of 3, no note', [$p['checked'], $p['total'], $p['note']], [1, 3, null]);

$p = WordpressIntegrity::parsePlugins(0, 'Success: Verified 2 of 3 plugins (1 skipped).', $skip);
check('only skipped → success, premium-x not verifiable', [$p['unverifiable'], $p['added'], $p['modified']], [['premium-x'], [], []]);
$p = WordpressIntegrity::parsePlugins(0, '', 'Warning: Could not retrieve the version for plugin no-version, skipping.');
check('plugin without version not verifiable', $p['unverifiable'], ['no-version']);

$p = WordpressIntegrity::parsePlugins(1, "Deprecated: something in /x.php on line 3\n[{\"plugin_name\":\"a\",\"file\":\"b.php\",\"message\":\"File was added\"}]", 'Error: Only verified 0 of 1 plugins (1 failed).');
check('PHP notice before the JSON', $p['added'], ['wp-content/plugins/a/b.php']);

$p = WordpressIntegrity::parsePlugins(255, "PHP Fatal error:  Uncaught Error: Failed opening required 'x' in /y.php:25\nStack trace:", '');
check('WordPress does not load → not verifiable with reason', $p['note'], "Uncaught Error: Failed opening required 'x' in /y.php:25");
check('  → no tally', $p['total'], null);

echo "--- PHP files in uploads\n";
// The scan expects the physical installation path (realpath), as
// WordpressCli::path() provides it; the temp directory may itself be a link.
$base = realpath(sys_get_temp_dir()) . '/froxlor-project-view-integrity-' . getmypid();
exec('rm -rf ' . escapeshellarg($base));
$wp = $base . '/wp';
$up = $wp . '/wp-content/uploads';
mkdir($up . '/2026/10', 0755, true);
mkdir($up . '/.hidden', 0755, true);
mkdir($up . '/a/b/c/d', 0755, true);
mkdir($base . '/foreign', 0755, true);
foreach (['2026/10/image.jpg', '2026/10/x.php', '2026/10/Y.PHTML', '2026/10/double.php.jpg', '2026/10/archive.phar',
          '.hidden/z.php', 'a/b/c/d/deep.php', 'index.html', 'php', 'extension.php5'] as $f) {
	file_put_contents($up . '/' . $f, '<?php echo 1;');
}
file_put_contents($base . '/foreign/outside.php', '<?php');
symlink($base . '/foreign', $up . '/linked');                // must not be entered
symlink('/etc/passwd', $up . '/2026/link.php');             // reported, not read
mkdir($up . '/folder.php', 0755);                           // directory, not a file

$u = WordpressIntegrity::findUploadsPhp($wp);
check('found: php, phtml, phar, hidden, deep, link with PHP name', $u['files'], [
	'wp-content/uploads/.hidden/z.php',
	'wp-content/uploads/2026/10/Y.PHTML',
	'wp-content/uploads/2026/10/archive.phar',
	'wp-content/uploads/2026/10/x.php',
	'wp-content/uploads/2026/link.php',
	'wp-content/uploads/a/b/c/d/deep.php',
]);
check('  → count', $u['total'], 6);
check('  → no note', $u['note'], null);
check('linked directory not entered', in_array('wp-content/uploads/linked/outside.php', $u['files'], true), false);

check('no uploads → nothing', WordpressIntegrity::findUploadsPhp($base . '/does-not-exist'), ['files' => [], 'total' => 0, 'note' => null]);

mkdir($base . '/wp2/wp-content', 0755, true);
symlink($base . '/foreign', $base . '/wp2/wp-content/uploads');
$u = WordpressIntegrity::findUploadsPhp($base . '/wp2');
check('uploads itself a link → not searched, with note', [$u['total'], $u['note']], [0, 'wp-content/uploads is a symbolic link and was not searched.']);

// An intermediate component (wp-content) swapped for a link into a foreign
// installation: uploads must not be searched there.
mkdir($base . '/victim/wp-content/uploads', 0755, true);
touch($base . '/victim/wp-content/uploads/foreign.php');
mkdir($base . '/wp4', 0755, true);
symlink($base . '/victim/wp-content', $base . '/wp4/wp-content');
$u = WordpressIntegrity::findUploadsPhp($base . '/wp4');
check('wp-content is a link → not searched, with note', [$u['total'], $u['files'], $u['note']], [0, [], 'wp-content/uploads lies behind a symbolic link and was not searched.']);

// The installation path itself reached through a link: refused as well.
symlink($base . '/wp', $base . '/wp-alias');
$u = WordpressIntegrity::findUploadsPhp($base . '/wp-alias');
check('installation path not physical → not searched', [$u['total'], $u['note']], [0, 'wp-content/uploads lies behind a symbolic link and was not searched.']);

mkdir($base . '/wp3/wp-content/uploads', 0755, true);
for ($i = 0; $i < 60; $i++) {
	touch(sprintf('%s/wp3/wp-content/uploads/s%02d.php', $base, $i));
}
$u = WordpressIntegrity::findUploadsPhp($base . '/wp3');
check('60 hits: list truncated, count complete', [count($u['files']), $u['total']], [WordpressIntegrity::PATHS_MAX, 60]);

echo "--- Summary\n";
$core = WordpressIntegrity::parseCore(1, '', "Warning: File doesn't verify against checksum: wp-includes/version.php");
$plugins = WordpressIntegrity::parsePlugins(0, 'Success: Verified 2 of 3 plugins (1 skipped).', $skip);
$z = WordpressIntegrity::summarize($core, $plugins, WordpressIntegrity::findUploadsPhp($wp));
check('counts', [$z['modified'], $z['added'], $z['missing'], $z['uploads']], [1, 0, 0, 6]);
check('uploads first in the path list', $z['details']['paths'][0], ['kind' => 'uploads', 'path' => 'wp-content/uploads/.hidden/z.php']);
check('core finding in the path list', end($z['details']['paths']), ['kind' => 'modified', 'path' => 'wp-includes/version.php']);
check('stored detail keys', array_keys($z['details']), ['core_note', 'plugins_checked', 'plugins_total', 'plugins_unverifiable', 'plugins_note', 'uploads_note', 'paths', 'paths_total']);
check('log line', WordpressIntegrity::logLine($z),
	'Integrity: 1 modified, 0 added, 0 missing, 6 PHP files in uploads · 2 of 3 plugins checked · no checksums on wordpress.org: premium-x');

$one = WordpressIntegrity::summarize(
	WordpressIntegrity::parseCore(0, '', ''),
	WordpressIntegrity::parsePlugins(0, 'Success: Verified 1 of 1 plugins.', ''),
	['files' => ['wp-content/uploads/a.php'], 'total' => 1, 'note' => null]
);
check('log line, one PHP file (singular)', WordpressIntegrity::logLine($one),
	'Integrity: 0 modified, 0 added, 0 missing, 1 PHP file in uploads · 1 of 1 plugins checked');

$z = WordpressIntegrity::summarize(
	WordpressIntegrity::parseCore(0, 'Success: WordPress installation verifies against checksums.', ''),
	WordpressIntegrity::parsePlugins(0, 'Success: Verified 2 of 2 plugins.', ''),
	WordpressIntegrity::findUploadsPhp($base . '/does-not-exist')
);
check('clean: all 0', [$z['modified'], $z['added'], $z['missing'], $z['uploads'], $z['details']['paths_total']], [0, 0, 0, 0, 0]);
check('  → log line', WordpressIntegrity::logLine($z), 'Integrity: no modified files · 2 of 2 plugins checked');

$z = WordpressIntegrity::summarize($core, $plugins, WordpressIntegrity::findUploadsPhp($base . '/wp3'));
check('truncated: at most PATHS_MAX paths, total is correct', [count($z['details']['paths']), $z['details']['paths_total']], [WordpressIntegrity::PATHS_MAX, 61]);

// Single-file plugins (Hello Dolly) lie directly in wp-content/plugins/.
$wp = $base . '/single';
mkdir($wp . '/wp-content/plugins/akismet', 0755, true);
touch($wp . '/wp-content/plugins/hello.php');
mkdir($base . '/elsewhere', 0755, true);
symlink($base . '/elsewhere', $wp . '/wp-content/plugins/linked');
$json = '[{"plugin_name":"hello","file":"hello.php","message":"Checksum does not match"},'
	. '{"plugin_name":"akismet","file":"akismet.php","message":"Checksum does not match"},'
	. '{"plugin_name":"linked","file":"x.php","message":"Checksum does not match"},'
	. '{"plugin_name":"../x","file":"y.php","message":"Checksum does not match"}]';
$p = WordpressIntegrity::parsePlugins(1, $json, 'Error: Only verified 0 of 4 plugins (4 failed).', $wp);
check('single-file plugin: plugins/<file>', $p['modified'][0], 'wp-content/plugins/hello.php');
check('plugin directory: plugins/<slug>/<file>', $p['modified'][1], 'wp-content/plugins/akismet/akismet.php');
check('a linked plugin directory is not followed', $p['modified'][2], 'wp-content/plugins/x.php');
check('a slug with a path separator is not looked up', $p['modified'][3], 'wp-content/plugins/../x/y.php');
$p = WordpressIntegrity::parsePlugins(1, $json, '');
check('without installation path: slug/file as reported', $p['modified'][0], 'wp-content/plugins/hello/hello.php');

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\n$failed failed\n" : "\nAll passed.\n";
exit($failed ? 1 : 0);
