<?php

namespace Froxlor\Ext\ProjectView;

/**
 * Integrity check of a WordPress installation: modified, injected and
 * missing files.
 *
 * Two sources:
 * - `wp core verify-checksums` and `wp plugin verify-checksums --all`
 *   compare the files against the checksums from wordpress.org. That runs
 *   through WP-CLI as the customer (WordpressMaintenance); this class only
 *   parses the output. Plugins for which wordpress.org has no checksums
 *   (premium plugins, custom code) are "not verifiable" — not a finding.
 * - PHP files below wp-content/uploads are not found by verify-checksums,
 *   because uploads belong to no package. Yet that is exactly where attackers
 *   like to drop their backdoor. findUploadsPhp() looks for them.
 *
 * Same security principle as WordpressScanner: findUploadsPhp() runs as root
 * over a customer directory and is therefore strictly read-only. It only reads
 * directory listings and file attributes, opens no file, includes nothing and
 * follows no symbolic link.
 *
 * No database access, so everything can be tested without Froxlor
 * (tests/wordpress-integrity.php).
 */
class WordpressIntegrity
{
	/** PHP-FPM executes these extensions, or PHP loads them as an archive. */
	public const PHP_EXTENSIONS = ['php', 'phtml', 'phar'];

	/** This many affected paths are stored and shown. */
	public const PATHS_MAX = 50;

	/** Limits for the uploads scan: a customer cannot hold up the cron. */
	private const UPLOADS_DEPTH = 16;
	private const UPLOADS_ENTRIES = 200000;

	private const CORE_MESSAGES = [
		"File doesn't verify against checksum: " => 'modified',
		'File should not exist: ' => 'added',
		"File doesn't exist: " => 'missing',
	];

	private const PLUGIN_MESSAGES = [
		'Checksum does not match' => 'modified',
		'File was added' => 'added',
		'File is missing' => 'missing',
	];

	/**
	 * Parses `wp core verify-checksums`.
	 *
	 * Findings appear as warnings on stderr. "File should not exist" does not
	 * set an error code — so the warnings count regardless of the return
	 * value. An error code without any finding means: not checked (no
	 * connection to wordpress.org, unknown version …).
	 *
	 * @return array{modified:string[], added:string[], missing:string[], note:?string}
	 */
	public static function parseCore(int $code, string $out, string $err): array
	{
		$r = ['modified' => [], 'added' => [], 'missing' => [], 'note' => null];
		foreach (preg_split('/\R/', $err . "\n" . $out) as $line) {
			$line = trim($line);
			if (!str_starts_with($line, 'Warning: ')) {
				continue;
			}
			$rest = substr($line, 9);
			foreach (self::CORE_MESSAGES as $prefix => $kind) {
				if (str_starts_with($rest, $prefix)) {
					$r[$kind][] = self::path(substr($rest, strlen($prefix)));
					continue 2;
				}
			}
		}
		if ($code !== 0 && $r['modified'] === [] && $r['added'] === [] && $r['missing'] === []) {
			$r['note'] = self::errorMessage($code, $out, $err);
		}

		return $r;
	}

	/**
	 * Parses `wp plugin verify-checksums --all --format=json`.
	 *
	 * Findings come as a JSON table on stdout, skipped plugins as warnings on
	 * stderr, the tally ("Verified 2 of 3 plugins") as the last line. Without
	 * the tally the command did not run through — for instance because
	 * WordPress itself no longer loads.
	 *
	 * WP-CLI reports a plugin by its slug and the file relative to the plugin.
	 * For a single-file plugin (Hello Dolly: slug "hello", file "hello.php")
	 * the file lies directly in wp-content/plugins/. With $installPath the
	 * real location is decided by looking whether wp-content/plugins/<slug> is
	 * a directory — without following symbolic links (lstat only, nothing is
	 * opened).
	 *
	 * @return array{modified:string[], added:string[], missing:string[], unverifiable:string[], checked:?int, total:?int, note:?string}
	 */
	public static function parsePlugins(int $code, string $out, string $err, ?string $installPath = null): array
	{
		$r = [
			'modified' => [], 'added' => [], 'missing' => [], 'unverifiable' => [],
			'checked' => null, 'total' => null, 'note' => null,
		];

		foreach (preg_split('/\R/', $err) as $line) {
			if (preg_match('/^Warning: Could not retrieve the (?:checksums for version .+ of plugin|version for plugin) (.+), skipping\.$/', trim($line), $m)) {
				$r['unverifiable'][] = self::path($m[1]);
			}
		}
		$r['unverifiable'] = array_values(array_unique($r['unverifiable']));

		if (preg_match('/verified (\d+) of (\d+) plugins?/i', $out . "\n" . $err, $m)) {
			$r['checked'] = (int)$m[1];
			$r['total'] = (int)$m[2];
		}

		foreach (self::jsonTable($out) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$kind = self::PLUGIN_MESSAGES[(string)($entry['message'] ?? '')] ?? 'modified';
			$r[$kind][] = self::path(self::pluginFile((string)($entry['plugin_name'] ?? '?'), (string)($entry['file'] ?? '?'), $installPath));
		}

		if ($r['total'] === null && $r['modified'] === [] && $r['added'] === [] && $r['missing'] === []) {
			if ($code !== 0) {
				$r['note'] = self::errorMessage($code, $out, $err);
			} else {
				// No tally and no error: there are no plugins.
				$r['checked'] = $r['total'] = 0;
			}
		}

		return $r;
	}

	/**
	 * Path of a reported plugin file, relative to the installation.
	 */
	private static function pluginFile(string $slug, string $file, ?string $installPath): string
	{
		$base = 'wp-content/plugins/';
		if ($installPath === null || $slug === '' || $slug === '.' || $slug === '..' || strpbrk($slug, "/\\\0") !== false) {
			return $base . $slug . '/' . $file;
		}
		$dir = rtrim($installPath, '/') . '/' . $base . $slug;
		$stat = @lstat($dir);
		$isDirectory = $stat !== false && ($stat['mode'] & 0170000) === 0040000;

		return $isDirectory ? $base . $slug . '/' . $file : $base . $file;
	}

	/**
	 * Searches wp-content/uploads for files that PHP would execute.
	 *
	 * Strictly read-only: scandir and lstat, no file is opened. Directories are
	 * only entered if they are not symbolic links themselves; links with a PHP
	 * extension are reported but not followed.
	 *
	 * $installPath must be the physical path (realpath, as WordpressCli::path()
	 * provides it). Every directory is only listed if its realpath is exactly
	 * the path built from there — that is, no component from the installation
	 * down, wp-content and uploads included, is a symbolic link. If the
	 * customer swaps a directory for a link between check and read, it is
	 * skipped instead of listing foreign directories.
	 *
	 * @return array{files:string[], total:int, note:?string} files relative to the installation, at most PATHS_MAX
	 */
	public static function findUploadsPhp(string $installPath): array
	{
		$relative = 'wp-content/uploads';
		$base = rtrim($installPath, '/') . '/' . $relative;
		$r = ['files' => [], 'total' => 0, 'note' => null];

		clearstatcache();
		if (@lstat($base) === false) {
			return $r; // no uploads, nothing to check
		}
		if (is_link($base)) {
			$r['note'] = Lang::t('%s is a symbolic link and was not searched.', $relative);
			return $r;
		}
		$real = realpath($base);
		if ($real === false || !is_dir($real)) {
			return $r;
		}
		if ($real !== $base) {
			$r['note'] = Lang::t('%s lies behind a symbolic link and was not searched.', $relative);
			return $r;
		}

		$stack = [[$base, 0]];
		$entries = 0;
		while ($stack !== []) {
			[$directory, $depth] = array_pop($stack);
			if (realpath($directory) !== $directory) {
				continue;
			}
			$names = @scandir($directory);
			if ($names === false) {
				$r['note'] ??= Lang::t('Not all directories below %s were readable.', $relative);
				continue;
			}
			foreach ($names as $name) {
				if ($name === '.' || $name === '..') {
					continue;
				}
				if (++$entries > self::UPLOADS_ENTRIES) {
					$r['note'] = Lang::t('Aborted after %d entries — %s was not searched completely.', self::UPLOADS_ENTRIES, $relative);
					break 2;
				}
				$path = $directory . '/' . $name;
				$info = @lstat($path);
				if ($info === false) {
					continue;
				}
				// Hidden directories are deliberately searched too: that is
				// exactly where attackers hide their files.
				if (($info['mode'] & 0170000) === 0040000) {
					if ($depth + 1 < self::UPLOADS_DEPTH) {
						$stack[] = [$path, $depth + 1];
					} else {
						$r['note'] ??= Lang::t('Below %s there are directories deeper than %d levels — they were not searched.', $relative, self::UPLOADS_DEPTH);
					}
					continue;
				}
				if (self::isPhpName($name)) {
					$r['total']++;
					if (count($r['files']) < self::PATHS_MAX) {
						$r['files'][] = self::path($relative . substr($path, strlen($base)));
					}
				}
			}
		}
		sort($r['files']);

		return $r;
	}

	public static function isPhpName(string $name): bool
	{
		$dot = strrpos($name, '.');

		return $dot !== false && in_array(strtolower(substr($name, $dot + 1)), self::PHP_EXTENSIONS, true);
	}

	/**
	 * Combines the three partial results for the database: the counts in
	 * full, the paths truncated to PATHS_MAX. Uploads first — a PHP file there
	 * is the clearest finding.
	 *
	 * @return array{modified:int, added:int, missing:int, uploads:int, details:array}
	 */
	public static function summarize(array $core, array $plugins, array $uploads): array
	{
		$paths = [];
		foreach ($uploads['files'] as $path) {
			$paths[] = ['kind' => 'uploads', 'path' => $path];
		}
		foreach ([$core, $plugins] as $part) {
			foreach (['modified', 'added', 'missing'] as $kind) {
				foreach ($part[$kind] as $path) {
					$paths[] = ['kind' => $kind, 'path' => $path];
				}
			}
		}
		$count = static fn(string $kind) => count($core[$kind]) + count($plugins[$kind]);

		return [
			'modified' => $count('modified'),
			'added' => $count('added'),
			'missing' => $count('missing'),
			'uploads' => (int)$uploads['total'],
			'details' => [
				'core_note' => $core['note'],
				'plugins_checked' => $plugins['checked'],
				'plugins_total' => $plugins['total'],
				'plugins_unverifiable' => array_slice($plugins['unverifiable'], 0, self::PATHS_MAX),
				'plugins_note' => $plugins['note'],
				'uploads_note' => $uploads['note'],
				'paths' => array_slice($paths, 0, self::PATHS_MAX),
				// The path list is truncated, the counts are not: uploads count
				// with their full number, even if only PATHS_MAX paths are kept.
				'paths_total' => count($paths) - count($uploads['files']) + (int)$uploads['total'],
			],
		];
	}

	/**
	 * One line for the job log.
	 */
	public static function logLine(array $z): string
	{
		$d = $z['details'];
		$parts = [];
		if ($z['modified'] + $z['added'] + $z['missing'] + $z['uploads'] === 0) {
			$parts[] = Lang::t('no modified files');
		} elseif ($z['uploads'] === 1) {
			$parts[] = Lang::t('%d modified, %d added, %d missing, %d PHP file in uploads', $z['modified'], $z['added'], $z['missing'], $z['uploads']);
		} else {
			$parts[] = Lang::t('%d modified, %d added, %d missing, %d PHP files in uploads', $z['modified'], $z['added'], $z['missing'], $z['uploads']);
		}
		if ($d['core_note'] !== null) {
			$parts[] = Lang::t('Core not verifiable: %s', $d['core_note']);
		}
		if ($d['plugins_note'] !== null) {
			$parts[] = Lang::t('Plugins not verifiable: %s', $d['plugins_note']);
		} elseif ($d['plugins_total'] !== null) {
			$parts[] = Lang::t('%d of %d plugins checked', $d['plugins_checked'], $d['plugins_total']);
		}
		if ($d['plugins_unverifiable'] !== []) {
			$parts[] = Lang::t('no checksums on wordpress.org: %s', implode(', ', $d['plugins_unverifiable']));
		}
		if ($d['uploads_note'] !== null) {
			$parts[] = $d['uploads_note'];
		}

		return Lang::t('Integrity: %s', implode(' · ', $parts));
	}

	/**
	 * The JSON table from stdout. PHP notices from WordPress occasionally land
	 * in front of it — then the last line starting with [ counts.
	 */
	private static function jsonTable(string $out): array
	{
		$out = trim($out);
		if ($out === '') {
			return [];
		}
		$data = json_decode($out, true);
		if (is_array($data)) {
			return $data;
		}
		foreach (array_reverse(preg_split('/\R/', $out)) as $line) {
			$line = trim($line);
			if (str_starts_with($line, '[')) {
				$data = json_decode($line, true);
				return is_array($data) ? $data : [];
			}
		}

		return [];
	}

	/**
	 * The most meaningful error line, short enough for display.
	 */
	private static function errorMessage(int $code, string $out, string $err): string
	{
		if ($code === 124) {
			return Lang::t('Time limit exceeded.');
		}
		$lines = array_map('trim', preg_split('/\R/', $err . "\n" . $out));
		$text = null;
		foreach ($lines as $line) {
			if (str_starts_with($line, 'Error: ')) {
				$text = substr($line, 7); // the last one counts
			}
		}
		if ($text === null) {
			foreach ($lines as $line) {
				if (preg_match('/^(PHP )?(Fatal error|Parse error):\s*(.+)$/', $line, $m)) {
					$text = $m[3];
					break;
				}
			}
		}
		$text ??= Lang::t('Return value %d', $code);

		return mb_strimwidth(self::path($text), 0, 200, '…', 'UTF-8');
	}

	/**
	 * Paths come from the customer directory: strip control characters, limit
	 * the length. Invalid UTF-8 is replaced by json_encode when storing.
	 */
	private static function path(string $text): string
	{
		return substr(preg_replace('/[\x00-\x1F\x7F]/', '?', trim($text)), 0, 255);
	}
}
