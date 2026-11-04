<?php

/**
 * Checks ProjectRepository and ResourceMapper against a real MariaDB with
 * Froxlor's original schema — the queries themselves, not a retelling of them.
 *
 * Needs a disposable database (touch nothing productive). One way with
 * Docker; pick the password yourself, the script reads it from the
 * environment:
 *
 *   export FROXLOR_TEST_PASS="$(openssl rand -hex 12)"
 *
 *   docker run -d --name froxlor-test -p 13306:3306 \
 *       -e MARIADB_ROOT_PASSWORD="$FROXLOR_TEST_PASS" \
 *       -e MARIADB_DATABASE=froxlor mariadb:10.11
 *
 *   # extract Froxlor's schema from the PHP heredoc and load it
 *   php -r 'preg_match("/<<<.?FROXLORSQL.?\R(.*)\RFROXLORSQL;/s",
 *       file_get_contents($argv[1]), $m); echo $m[1];' /path/to/froxlor/install/froxlor.sql.php \
 *       | docker exec -i -e MYSQL_PWD="$FROXLOR_TEST_PASS" froxlor-test mariadb -uroot froxlor
 *
 *   for f in migrations/001_init.sql tests/seed.sql; do
 *       docker exec -i -e MYSQL_PWD="$FROXLOR_TEST_PASS" froxlor-test mariadb -uroot froxlor < "$f"
 *   done
 *
 * Run:      php tests/database.php /path/to/froxlor
 * Clean up: docker rm -f froxlor-test
 *
 * FROXLOR_TEST_DSN and FROXLOR_TEST_USER override the connection.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$repo = __DIR__ . '/..';
$froxlor = $argv[1] ?? null;
if (!$froxlor || !is_file($froxlor . '/lib/tables.inc.php')) {
	fwrite(STDERR, "Usage: php tests/database.php /path/to/froxlor\n");
	exit(2);
}

$pdo = new PDO(
	getenv('FROXLOR_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=13306;dbname=froxlor;charset=utf8mb4',
	getenv('FROXLOR_TEST_USER') ?: 'root',
	getenv('FROXLOR_TEST_PASS') ?: '',
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Table constants from Froxlor (parsed, not included).
foreach (file($froxlor . '/lib/tables.inc.php') as $line) {
	if (preg_match("/^const (TABLE_\w+) = '([^']+)';/", $line, $m)) {
		define($m[1], $m[2]);
	}
}

// Minimal stand-ins for the two Froxlor dependencies.
eval('namespace Froxlor\Database; class Database {
	public static $pdo;
	public static function prepare($sql) { return self::$pdo->prepare($sql); }
	public static function query($sql) { return self::$pdo->query($sql); }
	public static function pexecute(&$stmt, $params = null, $b = true, $c = false) { return $stmt->execute($params ?: []); }
	public static function pexecute_first(&$stmt, $params = null, $b = true, $c = false) {
		$stmt->execute($params ?: []); return $stmt->fetch(\PDO::FETCH_ASSOC);
	}
}');
eval('namespace Froxlor; class CurrentUser {
	public static $fields = [];
	public static function getField($k) { return self::$fields[$k] ?? null; }
	public static function hasSession() { return false; }
}');
eval('namespace Froxlor; class Settings {
	public static $values = [];
	public static function Get($k) { return self::$values[$k] ?? null; }
}');
\Froxlor\Database\Database::$pdo = $pdo;

foreach (['Lang', 'ProjectRepository', 'ResourceMapper', 'WordpressJobs', 'BackupIntegration'] as $class) {
	require $repo . '/lib/Froxlor/Ext/ProjectView/' . $class . '.php';
}

use Froxlor\Ext\ProjectView\ProjectRepository;
use Froxlor\Ext\ProjectView\ResourceMapper;

$failures = 0;
$check = function (string $name, $actual, $expected) use (&$failures) {
	$ok = $actual === $expected;
	printf("  %s %-50s actual=%-26s expected=%s\n", $ok ? 'OK    ' : 'FAILED', $name,
		json_encode($actual), json_encode($expected));
	if (!$ok) {
		$failures++;
	}
};

// --- Tenant separation ---------------------------------------------------
\Froxlor\CurrentUser::$fields = ['customers_see_all' => 0, 'adminid' => 1];
$p = ProjectRepository::listProjects();
$check('Admin 1 sees only own project', array_column($p, 'domain'), ['customer1.example']);
$check('Standard subdomain hidden', in_array('customer1.server.example', array_column($p, 'domain'), true), false);
$check('Customer label (company preferred)', $p[0]['customer_label'], 'Example Ltd');
$check('Foreign project not retrievable', ProjectRepository::getProject(4), null);
$check('Standard subdomain is not a project page either', ProjectRepository::getProject(3), null);

\Froxlor\CurrentUser::$fields = ['customers_see_all' => 0, 'adminid' => 2];
$p2 = ProjectRepository::listProjects();
$check('Admin 2 sees only own project', array_column($p2, 'domain'), ['customer2.example']);
$check('Customer label without company', $p2[0]['customer_label'], 'Ben Smith');

\Froxlor\CurrentUser::$fields = ['customers_see_all' => 1, 'adminid' => 1];
$check('Admin with full visibility sees both', array_column(ProjectRepository::listProjects(), 'domain'), ['customer1.example', 'customer2.example']);

// --- Assignment targets --------------------------------------------------
// Regression: the standard subdomain (domain 3) is not a project and must
// therefore appear neither as an assignment target nor as a suggestion. It
// starts with the customer's login name and would otherwise even be the
// preselected suggestion for databases.
$targets = array_column(ResourceMapper::mainDomainsOfCustomer(1), 'domain');
$check('Assignment targets without standard subdomain', $targets, ['customer1.example']);
$check('Name suggestion does not hit the standard subdomain',
	array_column(ResourceMapper::suggestByName('customer1sql1', 1), 'domain'), ['customer1.example']);

// --- Key figures ---------------------------------------------------------
// The actual point: several detail tables must not multiply each other's counts.
$a = ProjectRepository::aggregateFor(1);
$check('Figure subdomains', $a['subdomains'], 1);
$check('Figure email addresses', $a['emails'], 3);
$check('Figure databases', $a['databases'], 2);
$check('Figure FTP accounts', $a['ftps'], 1);
$check('WordPress: oldest version counts', $a['wp_version'], '5.9');
$check('WordPress: number of installations', $a['wp_count'], 2);
$check('WordPress: detected as outdated', $a['wp_outdated'], true);

// --- Details -------------------------------------------------------------
$check('Subdomains of the project', array_column(ProjectRepository::getSubdomains(1), 'domain'), ['shop.customer1.example']);
$mails = ProjectRepository::getEmails(1);
$check('Email addresses', count($mails), 3);
// Sorted by email_full — so index by address, not by position.
$byAddress = array_column($mails, null, 'email_full');
$check('Forwarder detected (own address left out)', array_column($byAddress['info@customer1.example']['forwarders'], 'target'), ['boss@customer1.example']);
$check('Forwarder keeps its position (forwarderid)', array_column($byAddress['info@customer1.example']['forwarders'], 'index'), [1]);
$check('Destination equal to address is no forwarder', $byAddress['books@customer1.example']['forwarders'], []);
$check('Empty destination', $byAddress['mail@customer1.example']['forwarders'], []);
$check('Assigned databases', array_column(ProjectRepository::getDatabases(1), 'databasename'), ['customer1_test', 'customer1_wp']);
$check('Assigned FTP accounts', array_column(ProjectRepository::getFtps(1), 'username'), ['customer1_ftp1']);

// --- Unassigned ----------------------------------------------------------
// Unassigned are database 3 and FTP 2 and 3 (FTP 1 is assigned to project 1).
$check('Unassigned resources (full visibility)', ResourceMapper::countUnassigned(), 3);
$openFtp = ResourceMapper::unassignedFtps();
$check('Unassigned FTP accounts', array_column($openFtp, 'username'), ['customer1_shop', 'customer2_ftp1']);
$suggestion = $openFtp[0]['suggestions'];
$check('FTP suggestion via the path', count($suggestion) ? $suggestion[0]['domain'] : null, 'customer1.example');

\Froxlor\CurrentUser::$fields = ['customers_see_all' => 0, 'adminid' => 1];
$check('Unassigned resources (admin 1 only)', ResourceMapper::countUnassigned(), 1);

// --- Backup hook ---------------------------------------------------------
$check('Backup hook: assignments of customer 1', \Froxlor\Ext\ProjectView\BackupIntegration::resourceAssignments(1), [
	['domainid' => 1, 'type' => 'database', 'id' => 1],
	['domainid' => 1, 'type' => 'database', 'id' => 2],
	['domainid' => 1, 'type' => 'ftp', 'id' => 1],
]);
ResourceMapper::assign('ftp', 3, 1);
$check('Backup hook ignores a foreign resource mapped to customer 1', count(\Froxlor\Ext\ProjectView\BackupIntegration::resourceAssignments(1)), 3);
$check('Project page ignores it too', array_column(ProjectRepository::getFtps(1), 'username'), ['customer1_ftp1']);
$check('Owner check used by the Assign action', ResourceMapper::resourceCustomer('ftp', 3), 2);
ResourceMapper::unassign('ftp', 3, 1);

// --- Autologin hosts -----------------------------------------------------
\Froxlor\Settings::$values = ['system.hostname' => 'server.example', 'system.froxloraliases' => ''];
$check('Panel host names never allowed for autologin', in_array('customer1.server.example', ProjectRepository::customerHostsForDomain(1), true), false);

// --- Assign and unassign -------------------------------------------------
ResourceMapper::assign('database', 3, 4);
$check('Assignment created', count(ProjectRepository::getDatabases(4)), 1);
ResourceMapper::unassign('database', 3, 999);
$check('Unassign with wrong project has no effect', count(ProjectRepository::getDatabases(4)), 1);
ResourceMapper::unassign('database', 3, 4);
$check('Unassign with right project works', count(ProjectRepository::getDatabases(4)), 0);

exit($failures ? 1 : 0);
