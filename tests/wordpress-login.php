<?php
/**
 * Checks the autologin helpers without WordPress and without a database: the
 * strict site address validation, the host check and the generated
 * must-use plugin.
 * Usage:  php tests/wordpress-login.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\WordpressLogin;

require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Lang.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/WordpressLogin.php';
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

echo "--- normalizeSiteUrl\n";
$cases = [
	['https://customer.example', 'https://customer.example', 'plain https'],
	['http://customer.example/', 'http://customer.example/', 'trailing slash kept'],
	['HTTPS://Customer.Example:8443/blog', 'https://customer.example:8443/blog', 'scheme and host lower-cased, port and path kept'],
	['https://customer.example/sub/dir-1_a.b~c%20', 'https://customer.example/sub/dir-1_a.b~c%20', 'path characters'],
	['  https://customer.example  ', 'https://customer.example', 'surrounding whitespace trimmed'],
	['http://evil.example\\@customer.example', null, 'backslash and user info'],
	['http://evil.example\\.customer.example', null, 'backslash in host'],
	['http://user:pass@customer.example', null, 'user info'],
	['http://evil.example@customer.example', null, 'at sign'],
	['https://customer.example/?a=1', null, 'query'],
	['https://customer.example/#x', null, 'fragment'],
	['https://customer.example/a b', null, 'space in path'],
	["https://customer.example/a\nb", null, 'line break in path'],
	["https://customer.example\r\n/x", null, 'line break in host'],
	['https://customer.example:0', null, 'port 0'],
	['https://customer.example:65536', null, 'port out of range'],
	['https://customer..example', null, 'empty label'],
	['https://[::1]/', null, 'IPv6 literal'],
	['javascript:alert(1)', null, 'other scheme'],
	['//customer.example', null, 'scheme-relative'],
	['ftp://customer.example', null, 'ftp'],
	['', null, 'empty'],
	[null, null, 'null'],
	['https://customer.example/' . str_repeat('a', 3000), null, 'too long'],
];
if (function_exists('idn_to_ascii')) {
	$cases[] = ["https://b\u{00FC}cher.example/shop", 'https://xn--bcher-kva.example/shop', 'IDN host converted to punycode'];
}
foreach ($cases as [$input, $expected, $text]) {
	check($text, WordpressLogin::normalizeSiteUrl($input), $expected);
}

echo "--- link()\n";
[$secret, $public] = WordpressLogin::keyPair();
$install = ['id' => 7, 'siteurl' => 'https://www.customer.example/'];
$link = WordpressLogin::link($install, $secret, 'admin', ['customer.example']);
check('subdomain of a customer host is accepted', str_starts_with($link, 'https://www.customer.example/?' . WordpressLogin::QUERY_PARAMETER . '='), true);

$message = null;
try {
	WordpressLogin::link(['id' => 7, 'siteurl' => 'https://evil.example'], $secret, 'admin', ['customer.example']);
} catch (RuntimeException $e) {
	$message = $e->getMessage();
}
check('foreign host refused', $message !== null, true);
check('  → message names no customer-controlled host', $message !== null && !str_contains($message, 'evil'), true);

$message = null;
try {
	WordpressLogin::link(['id' => 7, 'siteurl' => 'http://evil.example\\@customer.example'], $secret, 'admin', ['customer.example']);
} catch (RuntimeException $e) {
	$message = $e->getMessage();
}
check('parser confusion refused as invalid', $message, 'The website address is invalid.');

$message = null;
try {
	WordpressLogin::link(['id' => 7, 'siteurl' => 'https://customerexample.evil'], $secret, 'admin', ['example.evil.customer', 'customer.example']);
} catch (RuntimeException $e) {
	$message = $e->getMessage();
}
check('suffix without dot does not count as subdomain', $message !== null, true);

echo "--- must-use plugin\n";
$plugin = WordpressLogin::muPlugin(7, $public);
$file = tempnam(sys_get_temp_dir(), 'froxlor-project-view-mu-');
file_put_contents($file, $plugin);
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
unlink($file);
check('generated plugin is valid PHP', $code, 0);
check('  → contains the public key', str_contains($plugin, $public), true);
check('  → bound to the installation', str_contains($plugin, '!== 7'), true);
check('  → no placeholder left', preg_match('/__[A-Z_]+__/', $plugin), 0);

echo $failed ? "\n$failed failed\n" : "\nAll passed.\n";
exit($failed ? 1 : 0);
