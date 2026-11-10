<?php

/**
 * Database connection for the command line helpers (apply.php, remove.php).
 *
 * Connects with exactly the credentials and options Froxlor itself uses
 * (lib/userdata.inc.php), mirroring Froxlor\Database\Database::getDB():
 * unix_socket when $sql['socket'] is set, otherwise host + port (default
 * 3306) and, only then, TLS with $sql['ssl']['caFile'] and
 * $sql['ssl']['verifyServerCertificate'].
 *
 * userdata.inc.php is NOT included. The file belongs to the panel user, and
 * these helpers usually run as root; including it would execute whatever the
 * panel user put there with root privileges. Instead it is tokenized and only
 * plain literal assignments are accepted ($sql = [...]; $sql['key'] = '...';
 * strings, nowdoc/heredoc without interpolation, numbers, booleans, null).
 * Anything else aborts with an error.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

/**
 * Reads the variables of a userdata.inc.php without executing it.
 *
 * @return array<string,mixed> variable name => value (e.g. 'sql', 'sql_root')
 * @throws RuntimeException on anything that is not a plain literal assignment
 */
function pv_read_userdata(string $file): array
{
	// Opened first, then checked through the open handle: a check by path
	// followed by a read by path could be raced by the panel user (swap in a
	// link to a root-only file between the two).
	$lstat = @lstat($file);
	if ($lstat === false || ($lstat['mode'] & 0170000) !== 0100000) {
		throw new RuntimeException("$file is not a regular file.");
	}
	$fh = @fopen($file, 'rb');
	if ($fh === false) {
		throw new RuntimeException("$file is not readable.");
	}
	$fstat = fstat($fh);
	if ($fstat === false || ($fstat['mode'] & 0170000) !== 0100000
		|| $fstat['dev'] !== $lstat['dev'] || $fstat['ino'] !== $lstat['ino']) {
		fclose($fh);
		throw new RuntimeException("$file changed while it was opened.");
	}
	// userdata.inc.php is a few hundred bytes; never read more than 64 KB.
	$code = stream_get_contents($fh, 65537);
	fclose($fh);
	if ($code === false) {
		throw new RuntimeException("$file is not readable.");
	}
	if (strlen($code) > 65536) {
		throw new RuntimeException("$file is larger than 64 KB.");
	}
	$tokens = array_values(array_filter(
		token_get_all($code),
		static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)
	));
	$pos = 0;
	$vars = [];

	$peek = static function () use (&$tokens, &$pos) {
		return $tokens[$pos] ?? null;
	};
	// Only the kind of token and its line, never its text: the file may hold
	// anything (or, after a race, be some other file), and the message is
	// printed on root's terminal.
	$fail = static function (string $what) use (&$tokens, &$pos, $file): void {
		$t = $tokens[$pos] ?? null;
		$near = is_array($t) ? token_name($t[0]) . ' (line ' . $t[2] . ')' : ($t === null ? 'end of file' : "'" . substr((string)$t, 0, 1) . "'");
		throw new RuntimeException("Unsupported content in $file: expected $what near $near.");
	};
	$expect = static function (string $char) use (&$tokens, &$pos, $fail): void {
		if (($tokens[$pos] ?? null) !== $char) {
			$fail("'$char'");
		}
		$pos++;
	};

	$value = null;
	$value = static function () use (&$value, &$tokens, &$pos, $peek, $fail, $expect) {
		$t = $peek();
		if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
			$close = ']';
			if ($t !== '[') {
				$pos++;
				$expect('(');
				$close = ')';
			} else {
				$pos++;
			}
			$result = [];
			while ($peek() !== $close) {
				$item = $value();
				if ($peek() === null) {
					$fail("'$close'");
				}
				if (is_array($peek()) && $peek()[0] === T_DOUBLE_ARROW) {
					$pos++;
					if (!is_int($item) && !is_string($item)) {
						$fail('a scalar array key');
					}
					$result[$item] = $value();
				} else {
					$result[] = $item;
				}
				if ($peek() === ',') {
					$pos++;
				} elseif ($peek() !== $close) {
					$fail("',' or '$close'");
				}
			}
			$pos++;
			return $result;
		}
		if ($t === '-') {
			$pos++;
			$n = $value();
			if (!is_int($n) && !is_float($n)) {
				$fail('a number');
			}
			return -$n;
		}
		if (!is_array($t)) {
			$fail('a value');
		}
		switch ($t[0]) {
			case T_CONSTANT_ENCAPSED_STRING:
				$pos++;
				$quote = $t[1][0];
				$body = substr($t[1], 1, -1);
				return $quote === "'"
					? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
					: stripcslashes($body);
			case T_START_HEREDOC:
				$pos++;
				$body = '';
				if (is_array($peek()) && $peek()[0] === T_ENCAPSED_AND_WHITESPACE) {
					$body = $peek()[1];
					$pos++;
				}
				if (!is_array($peek()) || $peek()[0] !== T_END_HEREDOC) {
					$fail('end of nowdoc/heredoc without interpolation');
				}
				$pos++;
				// The closing newline belongs to the delimiter, not to the value.
				$body = preg_replace('/\r?\n$/', '', $body);
				return str_contains($t[1], "'") ? $body : stripcslashes($body);
			case T_LNUMBER:
				$pos++;
				return intval($t[1], 0);
			case T_DNUMBER:
				$pos++;
				return (float)$t[1];
			case T_STRING:
				$word = strtolower($t[1]);
				if (in_array($word, ['true', 'false', 'null'], true)) {
					$pos++;
					return $word === 'null' ? null : $word === 'true';
				}
		}
		$fail('a literal value');
	};

	while ($peek() !== null) {
		$t = $peek();
		if (!is_array($t) || $t[0] !== T_VARIABLE) {
			$fail('a variable assignment');
		}
		$name = substr($t[1], 1);
		$pos++;
		$path = [];
		while ($peek() === '[') {
			$pos++;
			$key = $value();
			if (!is_int($key) && !is_string($key)) {
				$fail('a scalar array key');
			}
			$path[] = $key;
			$expect(']');
		}
		$expect('=');
		$v = $value();
		$expect(';');

		$ref = &$vars[$name];
		foreach ($path as $key) {
			if (!is_array($ref)) {
				$ref = [];
			}
			$ref = &$ref[$key];
		}
		$ref = $v;
		unset($ref);
	}

	return $vars;
}

/** PDO MySQL attribute: Pdo\Mysql::ATTR_* on PHP 8.4+, PDO::MYSQL_ATTR_* before (as Froxlor 2.3.16 does). */
function pv_pdo_mysql_attribute(string $name): int
{
	if (defined('Pdo\\Mysql::ATTR_' . $name)) {
		return constant('Pdo\\Mysql::ATTR_' . $name);
	}
	return constant('PDO::MYSQL_ATTR_' . $name);
}

/**
 * DSN and options for Froxlor's unprivileged database user, built like
 * Froxlor\Database\Database::getDB().
 *
 * @return array{dsn:string, user:string, password:string, options:array, db:string, host:string}
 */
function pv_connection_parameters(array $sql): array
{
	if (!isset($sql['db'], $sql['user'])) {
		throw new RuntimeException('userdata.inc.php contains no usable $sql configuration.');
	}

	$dsn = ['charset' => 'utf8', 'dbname' => (string)$sql['db']];
	$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
	$socket = $sql['socket'] ?? null;
	$host = (string)($sql['host'] ?? 'localhost');

	if ($socket !== null && $socket !== '') {
		$dsn['unix_socket'] = (string)$socket;
		$label = (string)$socket;
	} else {
		$dsn['host'] = $host;
		$dsn['port'] = (string)($sql['port'] ?? '3306');
		$label = $host . ':' . $dsn['port'];
		// Froxlor uses TLS only for TCP connections.
		$caFile = (string)($sql['ssl']['caFile'] ?? '');
		if ($caFile !== '') {
			$options[pv_pdo_mysql_attribute('SSL_CA')] = $caFile;
			$options[pv_pdo_mysql_attribute('SSL_VERIFY_SERVER_CERT')] = (bool)($sql['ssl']['verifyServerCertificate'] ?? false);
		}
	}

	$parts = [];
	foreach ($dsn as $k => $v) {
		$parts[] = $k . '=' . $v;
	}

	return [
		'dsn' => 'mysql:' . implode(';', $parts),
		'user' => (string)$sql['user'],
		'password' => (string)($sql['password'] ?? ''),
		'options' => $options,
		'db' => (string)$sql['db'],
		'host' => $label,
	];
}

/**
 * Connects to Froxlor's database.
 *
 * @return array{0: PDO, 1: string, 2: string, 3: string}  PDO, database, host, user
 */
function pv_connect(string $froxlor): array
{
	$file = rtrim($froxlor, '/') . '/lib/userdata.inc.php';
	if ($froxlor === '' || !is_file($file)) {
		fwrite(STDERR, "lib/userdata.inc.php not found or not readable.\n");
		exit(1);
	}
	// A link would let the panel user point root at any file.
	if (is_link($file)) {
		fwrite(STDERR, "lib/userdata.inc.php is a symbolic link; it is not read.\n");
		exit(1);
	}

	try {
		$vars = pv_read_userdata($file);
		$p = pv_connection_parameters(is_array($vars['sql'] ?? null) ? $vars['sql'] : []);
	} catch (RuntimeException $e) {
		fwrite(STDERR, $e->getMessage() . "\n");
		exit(1);
	}

	try {
		$pdo = new PDO($p['dsn'], $p['user'], $p['password'], $p['options']);
		$pdo->exec('SET names utf8');
	} catch (PDOException $e) {
		fwrite(STDERR, 'Connection failed: ' . $e->getMessage() . "\n");
		exit(1);
	}

	return [$pdo, $p['db'], $p['host'], $p['user']];
}
