<?php
/**
 * Checks the process handling of WordpressCli without root and without
 * WP-CLI: working directory, output caps, stderr handling, the verified cd
 * into the installation, the size cap for backup streams and the scrubbed
 * environment of customer processes.
 * Usage:  php tests/wordpress-cli.php
 *
 * The privilege drop itself (setpriv) needs root on Linux and is not run here.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\WordpressCli;

require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Lang.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/WordpressCli.php';
Lang::set('en');

$failed = 0;
function check(string $what, $actual, $expected): void
{
	global $failed;
	$ok = $actual === $expected;
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $what);
	if (!$ok) {
		printf("         actual:   %s\n         expected: %s\n", var_export($actual, true), var_export($expected, true));
		$failed++;
	}
}

$class = new ReflectionClass(WordpressCli::class);
$start = $class->getMethod('start');
$redirect = $class->getMethod('targetRedirect');
$finish = $class->getMethod('finish');
$enter = $class->getConstant('ENTER_SCRIPT');

echo "--- processes\n";
check('working directory is /', WordpressCli::asRoot(['pwd'])['out'], '/');

$r = WordpressCli::asRoot(['sh', '-c', 'head -c 3000000 /dev/zero | tr "\\000" a']);
check('stdout flood: aborted with 124', $r['code'], 124);
check('  → stdout capped at 2 MiB', strlen($r['out']), 2097152);

$r = WordpressCli::asRoot(['sh', '-c', 'head -c 3000000 /dev/zero | tr "\\000" b >&2; echo done']);
check('stderr flood: completes', [$r['code'], $r['out']], [0, 'done']);
check('  → stderr capped at 1 MiB', strlen($r['err']), 1048576);

$r = WordpressCli::asRoot(['sh', '-c', 'for i in 1 2 3 4 5 6; do head -c 200000 /dev/zero | tr "\\000" o; head -c 200000 /dev/zero | tr "\\000" e >&2; done']);
check('large stdout and stderr interleaved: no deadlock', [$r['code'], strlen($r['out']), strlen($r['err'])], [0, 1200000, 1048576]);

$r = WordpressCli::asRoot(['sh', '-c', 'echo out; echo err >&2; exit 3']);
check('exit code, stdout and stderr kept apart', [$r['code'], $r['out'], $r['err']], [3, 'out', 'err']);

echo "--- verified cd into the installation\n";
$base = realpath(sys_get_temp_dir()) . '/froxlor-project-view-cli-' . getmypid();
exec('rm -rf ' . escapeshellarg($base));
mkdir($base . '/customer/site', 0755, true);
mkdir($base . '/other/site', 0755, true);
symlink($base . '/other', $base . '/customer/swapped');
symlink($base . '/other/site', $base . '/customer/link');

$r = WordpressCli::asRoot(['bash', '-c', $enter, 'test', $base . '/customer/site', 'pwd']);
check('physical path: command runs inside it', [$r['code'], $r['out']], [0, $base . '/customer/site']);
$r = WordpressCli::asRoot(['bash', '-c', $enter, 'test', $base . '/customer/link', 'pwd']);
check('installation is a link: refused', [$r['code'], $r['out']], [WordpressCli::EXIT_PATH_CHANGED, '']);
$r = WordpressCli::asRoot(['bash', '-c', $enter, 'test', $base . '/customer/swapped/site', 'pwd']);
check('intermediate component is a link: refused', [$r['code'], $r['out']], [WordpressCli::EXIT_PATH_CHANGED, '']);
$r = $finish->invoke(null, $r, null, null);
check('  → readable message', $r['err'], 'The installation path changed or contains a symbolic link — aborted.');
$r = WordpressCli::asRoot(['bash', '-c', $enter, 'test', $base . '/customer/missing', 'pwd']);
check('missing directory: refused', $r['code'], WordpressCli::EXIT_PATH_CHANGED);
$r = WordpressCli::asRoot(['bash', '-c', $enter, 'test', $base . '/customer/site', 'sh', '-c', 'printf "%s|%s" "$1" "$2"', 'sh', 'a b', '$(id)']);
check('arguments pass through unchanged', $r['out'], 'a b|$(id)');

echo "--- size cap for backup streams\n";
$target = $base . '/stream.bin';
$line = "'sh' '-c' 'head -c 5000 /dev/zero'";
$r = $finish->invoke(null, $start->invoke(null, $line . $redirect->invoke(null, $target, 1000), null, 30), $target, 1000);
check('over the cap: reported', $r['limit_exceeded'], true);
check('  → at most one byte more written', filesize($target), 1001);
$r = $finish->invoke(null, $start->invoke(null, $line . $redirect->invoke(null, $target, 5000), null, 30), $target, 5000);
check('exactly at the cap: fine', [$r['limit_exceeded'], filesize($target)], [false, 5000]);
$r = $finish->invoke(null, $start->invoke(null, $line . $redirect->invoke(null, $target, null), null, 30), $target, null);
check('without cap: plain redirect', [$r['limit_exceeded'], filesize($target)], [false, 5000]);

echo "--- environment of customer processes\n";
$cli = $class->newInstanceWithoutConstructor();
foreach (['path' => $base . '/customer/site', 'uid' => 10001, 'gid' => 10001, 'wpcli' => '/usr/local/bin/wp', 'home' => '/var/lib/froxlor-project-view/wpcli/10001'] as $property => $value) {
	$class->getProperty($property)->setValue($cli, $value);
}
$prefix = $class->getMethod('asCustomerPrefix')->invoke($cli, 30, ['EXTRA=1']);
$position = static fn(string $word) => array_search($word, $prefix, true);
check('order: timeout, setpriv, env -i, bash', $position('timeout') < $position('setpriv') && $position('setpriv') < $position('env') && $position('env') < $position('bash'), true);
check('  → env starts empty (-i)', $prefix[$position('env') + 1], '-i');
$environment = array_slice($prefix, $position('env') + 2, $position('bash') - $position('env') - 2);
check('  → only the allowlist, HOME and the extras', $environment, [
	'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
	'LANG=C.UTF-8',
	'LC_ALL=C.UTF-8',
	'HOME=/var/lib/froxlor-project-view/wpcli/10001',
	'EXTRA=1',
]);
check('  → the expected path follows the script', array_slice($prefix, -2), ['froxlor-project-view', $base . '/customer/site']);
check('  → setpriv drops groups and new privileges', in_array('--clear-groups', $prefix, true) && in_array('--no-new-privs', $prefix, true), true);

exec('rm -rf ' . escapeshellarg($base));
echo $failed ? "\n$failed failed\n" : "\nAll passed.\n";
exit($failed ? 1 : 0);
