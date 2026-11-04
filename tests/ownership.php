<?php

/**
 * Ownership checks without MySQL, on an in-memory SQLite database with the
 * few Froxlor columns involved:
 *
 *   php tests/ownership.php
 *
 * - BackupIntegration::resourceAssignments() returns only resources and
 *   projects of the asked customer, also when the mapping table contains a
 *   foreign (manipulated) row.
 * - ProjectRepository::getDatabases()/getFtps() ignore mappings to another
 *   customer's resources.
 * - ResourceMapper::resourceCustomer()/assignedProject(), which the
 *   Unassigned → Assign action uses to refuse foreign resources.
 * - ProjectRepository::customerHostsForDomain() never allows the panel's own
 *   host names (they would receive the panel session cookie).
 *
 * tests/database.php covers the full queries against Froxlor's real schema.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

if (!extension_loaded('pdo_sqlite')) {
	echo "SKIPPED: pdo_sqlite is not available.\n";
	exit(0);
}

define('TABLE_PANEL_DOMAINS', 'panel_domains');
define('TABLE_PANEL_CUSTOMERS', 'panel_customers');
define('TABLE_PANEL_DATABASES', 'panel_databases');
define('TABLE_FTP_USERS', 'ftp_users');

eval('namespace Froxlor\Database; class Database {
	public static $pdo;
	public static function prepare($sql) { return self::$pdo->prepare($sql); }
	public static function query($sql) { return self::$pdo->query($sql); }
	public static function pexecute(&$stmt, $params = null, $b = true, $c = false) { return $stmt->execute($params ?: []); }
	public static function pexecute_first(&$stmt, $params = null, $b = true, $c = false) {
		$stmt->execute($params ?: []); return $stmt->fetch(\PDO::FETCH_ASSOC);
	}
}');
eval('namespace Froxlor; class Settings {
	public static $values = [];
	public static function Get($k) { return self::$values[$k] ?? null; }
}');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\Froxlor\Database\Database::$pdo = $pdo;
$pdo->exec('CREATE TABLE panel_domains (id INTEGER PRIMARY KEY, domain TEXT, domain_ace TEXT, customerid INTEGER)');
$pdo->exec('CREATE TABLE panel_databases (id INTEGER PRIMARY KEY, customerid INTEGER, databasename TEXT, description TEXT, dbserver INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE ftp_users (id INTEGER PRIMARY KEY, customerid INTEGER, username TEXT, homedir TEXT, description TEXT, login_enabled TEXT)');
$pdo->exec('CREATE TABLE ext_projectview_resources (id INTEGER PRIMARY KEY, domainid INTEGER, resource_type TEXT, resource_id INTEGER, assigned_at INTEGER)');

$pdo->exec("INSERT INTO panel_domains VALUES
	(1, 'customer1.example', 'customer1.example', 1),
	(2, 'shop.customer1.example', 'shop.customer1.example', 1),
	(3, 'customer1.panel.example', 'customer1.panel.example', 1),
	(4, 'customer2.example', 'customer2.example', 2)");
$pdo->exec("INSERT INTO panel_databases VALUES (1, 1, 'c1_wp', '', 0), (2, 1, 'c1_test', '', 0), (3, 2, 'c2_wp', '', 0)");
$pdo->exec("INSERT INTO ftp_users VALUES (1, 1, 'c1_ftp', '/x/', '', 'Y'), (2, 2, 'c2_ftp', '/y/', '', 'Y')");
// Row 4 and 5 are foreign: customer 2's database/FTP mapped to customer 1's project.
$pdo->exec("INSERT INTO ext_projectview_resources (domainid, resource_type, resource_id, assigned_at) VALUES
	(1, 'database', 1, 0), (1, 'ftp', 1, 0), (2, 'database', 2, 0),
	(1, 'database', 3, 0), (1, 'ftp', 2, 0)");

foreach (['Lang', 'BackupIntegration', 'ProjectRepository', 'ResourceMapper'] as $class) {
	require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/' . $class . '.php';
}

use Froxlor\Ext\ProjectView\BackupIntegration;
use Froxlor\Ext\ProjectView\ProjectRepository;
use Froxlor\Ext\ProjectView\ResourceMapper;

$failures = 0;
$check = static function (string $name, $actual, $expected) use (&$failures): void {
	$ok = $actual === $expected;
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $name . ($ok ? '' : ': ' . json_encode($actual) . ' expected ' . json_encode($expected)));
	if (!$ok) {
		$failures++;
	}
};

echo "BackupIntegration::resourceAssignments()\n";
$check('customer 1: own resources only, foreign mappings ignored', BackupIntegration::resourceAssignments(1), [
	['domainid' => 1, 'type' => 'database', 'id' => 1],
	['domainid' => 1, 'type' => 'ftp', 'id' => 1],
	['domainid' => 2, 'type' => 'database', 'id' => 2],
]);
$check('customer 2: its resources are mapped to a foreign project, so nothing', BackupIntegration::resourceAssignments(2), []);
$check('unknown customer', BackupIntegration::resourceAssignments(99), []);

echo "Project reads\n";
$check('databases of project 1 without the foreign one', array_column(ProjectRepository::getDatabases(1), 'databasename'), ['c1_wp']);
$check('FTP accounts of project 1 without the foreign one', array_column(ProjectRepository::getFtps(1), 'username'), ['c1_ftp']);

echo "Assign checks\n";
$check('owner of a database', ResourceMapper::resourceCustomer('database', 3), 2);
$check('owner of an FTP account', ResourceMapper::resourceCustomer('ftp', 1), 1);
$check('missing resource', ResourceMapper::resourceCustomer('database', 99), null);
$check('invalid type', ResourceMapper::resourceCustomer('mailbox', 1), null);
$check('current project of a resource', ResourceMapper::assignedProject('database', 2), 2);
$check('unassigned resource', ResourceMapper::assignedProject('database', 99), null);

echo "Autologin hosts\n";
\Froxlor\Settings::$values = ['system.hostname' => 'panel.example', 'system.froxloraliases' => 'admin.example, '];
$pdo->exec("INSERT INTO panel_domains VALUES (5, 'admin.example', 'admin.example', 1)");
$check('panel host name and aliases (and their subdomains) are excluded',
	ProjectRepository::customerHostsForDomain(1),
	['customer1.example', 'shop.customer1.example']);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n$failures check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
