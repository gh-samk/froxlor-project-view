<?php

/**
 * Escaping of output that Froxlor prints with |raw:
 *
 *   php tests/web-output.php
 *
 * - Error messages (Response::dynamicError) go through Html::errorMessage:
 *   no markup, no Vue {{ … }} survives. Regression test for a stored XSS via
 *   a WordPress site address chosen by the customer.
 * - admin_projects.php calls Response::dynamicError only inside pv_error().
 * - The document root column of the overview is escaped.
 *
 * tests/templates.php additionally renders a message in Froxlor's real
 * alert box template.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Html.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Callbacks/Project.php';

use Froxlor\Ext\ProjectView\Callbacks\Project;
use Froxlor\Ext\ProjectView\Html;

$failures = 0;
$check = static function (bool $ok, string $text) use (&$failures): void {
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) {
		$failures++;
	}
};

echo "Error messages\n";
foreach ([
	'markup' => 'Host http://<img src=x onerror=alert(document.cookie)> is not allowed',
	'Vue expression' => 'Name {{ constructor.constructor("alert(1)")() }}',
	'quotes' => "a\"b'c",
	'invalid UTF-8' => "abc\xff",
] as $case => $message) {
	$html = Html::errorMessage($message);
	$inner = substr($html, strlen('<span v-pre>'), -strlen('</span>'));
	$check(str_starts_with($html, '<span v-pre>') && str_ends_with($html, '</span>'), "$case: wrapped in v-pre");
	$check(!preg_match('/[<>"\'{}]/', $inner), "$case: no raw < > \" ' { } inside");
}
$check(html_entity_decode(strip_tags(Html::errorMessage("\u{00C4} & {x}")), ENT_QUOTES | ENT_HTML5, 'UTF-8') === "\u{00C4} & {x}", 'text stays readable');

echo "admin_projects.php\n";
$source = file_get_contents(__DIR__ . '/../admin_projects.php');
$check(substr_count($source, 'Response::dynamicError(') === 1 && str_contains($source, 'Response::dynamicError(Html::errorMessage($message))'),
	'Response::dynamicError is only called by pv_error()');
$check(!preg_match('/pv_error\([^;]*\$_(GET|POST|REQUEST)/', $source), 'no request data passed straight to an error');

echo "Overview columns\n";
$cell = Project::documentroot(['fields' => ['documentroot' => 'https://x.example/"><script>alert(1)</script>']]);
$check(!str_contains($cell, '<script') && str_contains($cell, '&lt;script'), 'document root / redirect target is escaped');

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
