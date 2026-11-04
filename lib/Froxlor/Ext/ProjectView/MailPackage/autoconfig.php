<?php

/**
 * Entry point for autoconfig.<domain> and autodiscover.<domain>.
 *
 * Wired in through the special settings of these subdomains
 * (MailPackage::vhostLines). The Apache alias routes only the two standard
 * paths here; everything else in this directory is denied.
 */

require __DIR__ . '/Autoconfiguration.php';

use Froxlor\Ext\ProjectView\MailPackage\Autoconfiguration;

$domain = Autoconfiguration::domainFromHost((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($domain === null) {
	http_response_code(404);
	exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: max-age=3600');

$path = strtolower((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
if (str_ends_with($path, '/autodiscover/autodiscover.xml')) {
	$request = (string)file_get_contents('php://input', false, null, 0, Autoconfiguration::MAX_REQUEST + 1);
	echo Autoconfiguration::outlook($domain, Autoconfiguration::addressFromRequest($request, $domain));
} else {
	echo Autoconfiguration::thunderbird($domain);
}
