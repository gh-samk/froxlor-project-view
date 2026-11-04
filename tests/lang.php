<?php

/**
 * Checks the translations of Project View (English source, German catalog):
 *
 *   php tests/lang.php            check everything
 *   php tests/lang.php <path>…    only check source files below these paths
 *
 * - Every text in Lang::t('…') / self::german('…') (PHP) and pv_t('…') (Twig)
 *   needs a German entry in lib/Froxlor/Ext/ProjectView/lang/de.php.
 * - So do the labels in WordpressJobs::TYPES, MailPackage::KINDS,
 *   MailPackage::STATUS and SubdomainSsl::STATUS, which are translated at
 *   the point of use.
 * - Placeholders (%s, %d, %1$s …) must match between English and German.
 * - Catalog entries that are used nowhere are notes, not errors.
 * The texts must be ONE literal in the source (no concatenation), otherwise
 * this script cannot find them.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__);
$lib = $root . '/lib/Froxlor/Ext/ProjectView';
$catalog = require $lib . '/lang/de.php';

$targets = array_slice($argv, 1) ?: [$root];
$files = [];
foreach ($targets as $target) {
	$target = realpath($target) ?: $target;
	if (is_file($target)) {
		$files[] = $target;
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (preg_match('~/(\.git|tests|migrations)/~', $path) || !preg_match('~\.(php|twig)$~', $path)) {
			continue;
		}
		if (str_contains($path, '/lang/') || str_ends_with($path, '/Lang.php')) {
			continue;
		}
		$files[] = $path;
	}
}

$unquote = static function (string $lit): string {
	$q = $lit[0];
	$in = substr($lit, 1, -1);
	return $q === "'" ? strtr($in, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($in);
};
$spec = static function (string $s): array {
	preg_match_all('/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', str_replace('%%', '', $s), $m);
	$l = $m[0];
	sort($l);
	return $l;
};

$used = [];
$errors = 0;
$notes = 0;
$check = static function (string $en, string $where) use (&$used, &$errors, $catalog, $spec): void {
	$used[$en] = true;
	if (!isset($catalog[$en])) {
		echo "  FAIL $where  no German entry: " . mb_substr($en, 0, 70) . "\n";
		$errors++;
		return;
	}
	if ($spec($en) !== $spec($catalog[$en])) {
		echo "  FAIL $where  placeholders do not match: " . mb_substr($en, 0, 70) . "\n";
		$errors++;
	}
};

$literal = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
foreach ($files as $file) {
	$text = file_get_contents($file);
	$pattern = str_ends_with($file, '.twig')
		? '~(?<![\w.$>:])pv_t\(\s*' . $literal . '~s'
		: '~(?:Lang::t|self::german)\(\s*' . $literal . '~s';
	if (!preg_match_all($pattern, $text, $hits, PREG_OFFSET_CAPTURE)) {
		continue;
	}
	foreach ($hits[1] as [$lit, $offset]) {
		$line = substr_count(substr($text, 0, $offset), "\n") + 1;
		$check($unquote($lit), str_replace($root . '/', '', $file) . ':' . $line);
	}
}

if (count($targets) === 1 && $targets[0] === $root) {
	// Labels kept as English constants and translated where they are shown.
	foreach (['Lang', 'WordpressJobs', 'MailPackage', 'SubdomainSsl'] as $class) {
		require_once $lib . '/' . $class . '.php';
	}
	$constants = [
		'WordpressJobs::TYPES' => \Froxlor\Ext\ProjectView\WordpressJobs::TYPES,
		'MailPackage::KINDS' => \Froxlor\Ext\ProjectView\MailPackage::KINDS,
		'MailPackage::STATUS' => \Froxlor\Ext\ProjectView\MailPackage::STATUS,
		'SubdomainSsl::STATUS' => \Froxlor\Ext\ProjectView\SubdomainSsl::STATUS,
	];
	foreach ($constants as $name => $labels) {
		foreach ($labels as $label) {
			$check($label, $name);
		}
	}

	foreach (array_diff_key($catalog, $used) as $en => $de) {
		echo "  Note: catalog entry not used: " . mb_substr($en, 0, 70) . "\n";
		$notes++;
	}
}

// The language switch itself.
require_once $lib . '/Lang.php';
\Froxlor\Ext\ProjectView\Lang::set('Deutsch');
$german = \Froxlor\Ext\ProjectView\Lang::t('Projects') === ($catalog['Projects'] ?? null);
\Froxlor\Ext\ProjectView\Lang::set('en');
$english = \Froxlor\Ext\ProjectView\Lang::t('Projects') === 'Projects';
\Froxlor\Ext\ProjectView\Lang::set('de-DE');
$german2 = \Froxlor\Ext\ProjectView\Lang::iso() === 'de';
\Froxlor\Ext\ProjectView\Lang::set('Francais');
$other = \Froxlor\Ext\ProjectView\Lang::iso() === 'en';
foreach (['"Deutsch" yields German' => $german, '"en" yields English' => $english, '"de-DE" yields German' => $german2, 'any other language yields English' => $other] as $what => $ok) {
	if (!$ok) {
		echo "  FAIL language switch: $what\n";
		$errors++;
	}
}

echo count($used) . " texts used, " . count($catalog) . " in the catalog, $errors errors, $notes notes\n";
exit($errors > 0 ? 1 : 0);
