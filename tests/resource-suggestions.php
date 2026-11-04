<?php
/**
 * Checks the assignment suggestions for databases and FTP accounts — without a database.
 * Usage:  php tests/resource-suggestions.php
 *
 * Approach: load the class and replace mainDomainsOfCustomer with test data in
 * a subclass. Loading the file needs no Froxlor classes; they are only
 * resolved when a database method is actually called.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/Lang.php';
require __DIR__ . '/../lib/Froxlor/Ext/ProjectView/ResourceMapper.php';

class TestMapper extends \Froxlor\Ext\ProjectView\ResourceMapper
{
	public static array $domains = [];
	public static function mainDomainsOfCustomer(int $customerid): array { return self::$domains; }
}

TestMapper::$domains = [
	['id' => 1, 'domain' => 'customer-example.org', 'domain_ace' => 'customer-example.org', 'documentroot' => '/var/customers/webs/customer/'],
	['id' => 2, 'domain' => 'shop.customer.org',    'domain_ace' => 'shop.customer.org',    'documentroot' => '/var/customers/webs/customer/shop/'],
	['id' => 3, 'domain' => 'other.org',            'domain_ace' => 'other.org',            'documentroot' => '/var/customers/webs/other/'],
];

$cases = [
	// [kind, input, expected top ID or null, description]
	['path', '/var/customers/webs/customer/shop/',        2, 'deepest match wins (nested projects)'],
	['path', '/var/customers/webs/customer/',             1, 'exact match on the root'],
	['path', '/var/customers/webs/customer/shop/uploads', 2, 'subdirectory belongs to the more specific project'],
	['path', '/var/customers/webs/other/',                3, 'other project'],
	['path', '/var/customers/webs/foreign/',           null, 'no match'],
	['path', '/var/customers/webs/customer',              1, 'without trailing slash'],
	['path', '',                                       null, 'empty path'],
	// Prefix trap: must NOT match /customer/
	['path', '/var/customers/webs/customer-old/',      null, 'name prefix is not a directory match'],
	['name', 'cust_customerexample_wp',                   1, 'domain label in the database name'],
	['name', 'cust_shop_db',                              2, 'second project via the label'],
	['name', 'cust_something',                         null, 'no label contained'],
	['name', '',                                       null, 'empty name'],
];

$failed = 0;
foreach ($cases as [$kind, $input, $expectedId, $text]) {
	$matches = $kind === 'path'
		? TestMapper::suggestByPath($input, 1)
		: TestMapper::suggestByName($input, 1);
	$actualId = count($matches) ? (int)$matches[0]['id'] : null;
	$ok = $actualId === $expectedId;
	printf("  %s %-4s %-40s -> %-4s (expected %-4s)  %s\n",
		$ok ? 'OK    ' : 'FAILED', $kind, "'" . $input . "'",
		var_export($actualId, true), var_export($expectedId, true), $text);
	if (!$ok) { $failed++; }
}
echo $failed ? "\n$failed failed\n" : "\nAll passed.\n";
exit($failed ? 1 : 0);
