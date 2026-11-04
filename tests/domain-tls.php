<?php
/**
 * Checks the Let's Encrypt decisions for subdomains, the mail package, the
 * SNI files for Postfix/Dovecot and the mail client autoconfiguration —
 * without Froxlor and without a database.
 * Usage:  php tests/domain-tls.php
 *
 * The classes are loaded with their namespace and use lines stripped. Settings
 * is a stub; SNI writes into a throwaway directory and runs true or false
 * instead of postmap/doveconf.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

foreach (['Lang', 'SubdomainSsl', 'MailPackage', 'MailSni', 'MailPackage/Autoconfiguration'] as $class) {
	$src = file_get_contents(__DIR__ . '/../lib/Froxlor/Ext/ProjectView/' . $class . '.php');
	$src = preg_replace('/^namespace .*;$/m', '', $src);
	$src = preg_replace('/^use .*;$/m', '', $src);
	eval('?>' . $src);
}
// Without a session the panel default applies; the checks compare English texts.
Lang::set('en');

class Settings {
	public static array $values = [];
	public static function Get($k) { return self::$values[$k] ?? null; }
	public static function IsInList($k, $v) { return in_array($v, explode(',', (string)self::Get($k)), true); }
}

$failures = 0;
function check(bool $ok, string $text): void {
	global $failures;
	printf("  %s %s\n", $ok ? 'OK    ' : 'FAILED', $text);
	if (!$ok) { $failures++; }
}

$now = 1_800_000_000;
$yes = static fn() => true;
$no = static fn() => false;
$neverAsk = static function () { throw new RuntimeException('DNS must not have been queried'); };

echo "Subdomain Let's Encrypt\n";
check(SubdomainSsl::decide(null, $yes, $now) === 'enable', 'new subdomain, DNS matches: enable');
check(SubdomainSsl::decide(null, $no, $now) === 'wait', 'new subdomain, DNS does not match: wait');
$waiting = ['status' => 'waiting_dns', 'first_attempt_at' => $now - 100, 'last_attempt_at' => $now - 100];
check(SubdomainSsl::decide($waiting, $neverAsk, $now) === 'nothing', 'waiting, last attempt just now: no DNS lookup');
$waiting['last_attempt_at'] = $now - 3600;
check(SubdomainSsl::decide($waiting, $yes, $now) === 'enable', 'waiting, after an hour DNS matches: enable');
check(SubdomainSsl::decide($waiting, $no, $now) === 'wait', 'waiting, DNS still wrong: wait');
$waiting['first_attempt_at'] = $now - 7 * 86400;
check(SubdomainSsl::decide($waiting, $no, $now) === 'give_up', 'after 7 days without DNS: give up');
foreach (['done', 'given_up'] as $s) {
	check(SubdomainSsl::decide(['status' => $s] + $waiting, $neverAsk, $now) === 'nothing', "already $s: never touch again (even if LE is turned off later)");
}
$failedEntry = ['status' => 'failed', 'first_attempt_at' => $now - 100, 'last_attempt_at' => $now - 100];
check(SubdomainSsl::decide($failedEntry, $neverAsk, $now) === 'nothing', 'error just now: do not retry immediately');
check(SubdomainSsl::decide(['last_attempt_at' => $now - 3600] + $failedEntry, $yes, $now) === 'enable', 'error an hour ago: try again');
check(SubdomainSsl::decide(['last_attempt_at' => $now - 3600, 'first_attempt_at' => $now - 7 * 86400] + $failedEntry, $neverAsk, $now) === 'give_up', 'error for 7 days: give up');
check(SubdomainSsl::shorten("Error\n<b>here</b>   and  there") === 'Error here and there', 'message on one line and without HTML');
check(array_keys(SubdomainSsl::statusLabels()) === array_keys(SubdomainSsl::STATUS), 'status labels cover every status');

echo "Mail package\n";
$k = ['mode' => 'on', 'system_names' => ['panel.example.org', 'mail.example.org'], 'existing' => null, 'socket' => true, 'apache' => true, 'le' => true];
$plan = static fn($kind, $entry, $context, $dns = null, $domain = 'customer.example') => MailPackage::plan($domain, $kind, $entry, $context, $dns ?? static fn() => true, $now);
check($plan('webmail', null, $k)['action'] === 'create', 'new, everything fits: create');
check($plan('mail', null, ['mode' => 'dry_run'] + $k)['status'] === 'planned', 'dry run: only planned');
check($plan('mail', null, ['mode' => 'dry_run'] + $k)['action'] === 'record', 'dry run: creates nothing');
check($plan('mail', null, $k, null, 'example.org')['status'] === 'skipped', 'mail.example.org belongs to the panel: skipped');
check($plan('webmail', null, ['existing' => ['id' => 9]] + $k)['status'] === 'existing', 'already exists: existing, do not touch');
check($plan('webmail', null, ['socket' => false] + $k)['status'] === 'skipped', 'webmail without PHP socket: skipped');
check($plan('mail', null, ['socket' => false] + $k)['action'] === 'create', 'mail. needs no socket');
check($plan('autoconfig', null, ['apache' => false] + $k)['status'] === 'skipped', 'autoconfig without Apache: skipped');
check($plan('mail', null, $k, static fn() => false)['status'] === 'waiting_dns', 'DNS points elsewhere: waiting_dns');
$created = ['status' => 'created', 'subdomainid' => 9, 'message' => '', 'changed_at' => 0];
check($plan('mail', $created, $k)['status'] === 'deleted', 'created, but gone: deleted');
check($plan('mail', ['status' => 'deleted'] + $created, $k, $neverAsk)['action'] === 'nothing', 'deleted: not created again');
check($plan('webmail', $created, ['existing' => ['id' => 9]] + $k)['action'] === 'maintain', 'created webmail: maintain vhost lines');
check($plan('mail', $created, ['existing' => ['id' => 9]] + $k)['action'] === 'nothing', 'created mail.: nothing to maintain');
check($plan('webmail', $created, ['existing' => ['id' => 12]] + $k, $neverAsk)['status'] === 'deleted', 'customer created their own of the same name: do not touch');
check($plan('webmail', $created, ['mode' => 'off', 'existing' => ['id' => 9]] + $k)['action'] === 'maintain', 'mode off: what was created keeps being maintained');
check($plan('webmail', null, ['mode' => 'off'] + $k, $neverAsk)['action'] === 'nothing', 'mode off: nothing new');
check($plan('mail', null, ['le' => false] + $k, $neverAsk)['status'] === 'skipped', 'Let\'s Encrypt off in Froxlor: skipped');
$pending = ['status' => 'waiting_dns', 'subdomainid' => 0, 'message' => 'x', 'changed_at' => $now - 60];
check($plan('mail', $pending, $k, $neverAsk)['action'] === 'nothing', 'waiting, just checked: no DNS lookup');
$pending['changed_at'] = $now - 3600;
check($plan('mail', $pending, $k)['action'] === 'create', 'waiting, after an hour DNS matches: create');
check(array_keys(MailPackage::statusLabels()) === array_keys(MailPackage::STATUS) && array_keys(MailPackage::kindLabels()) === array_keys(MailPackage::KINDS), 'labels cover every status and kind');
Settings::$values['ext_projectview.mail_package_mode'] = 'nonsense';
check(MailPackage::mode() === 'dry_run', 'unknown mode: dry run');
Settings::$values['ext_projectview.mail_package_mode'] = 'on';
check(MailPackage::mode() === 'on', 'mode on');

$paths = ['challenge' => '/var/www/html/froxlor', 'roundcube' => '/var/lib/roundcube/public_html/', 'socket' => '/run/php/webapps.sock', 'package' => '/var/www/html/froxlor/lib/Froxlor/Ext/ProjectView/MailPackage'];
$webmail = MailPackage::vhostLines('webmail', $paths);
$lines = explode("\n", $webmail);
check(str_starts_with($lines[0], MailPackage::MARKER), 'lines carry the marker');
check($lines[1] === 'Alias "/.well-known/acme-challenge" "/var/www/html/froxlor/.well-known/acme-challenge"', 'ACME challenge comes before "Alias /"');
check(str_contains($webmail, 'Alias "/" "/var/lib/roundcube/public_html/"'), 'Roundcube without double slash');
check(str_contains($webmail, 'SetHandler "proxy:unix:/run/php/webapps.sock|fcgi://froxlor-project-view-webapps"'), 'PHP via the pool socket');
$auto = MailPackage::vhostLines('autoconfig', $paths);
check(preg_match('#AliasMatch "(.+?)" "/var/www/html/froxlor/lib/Froxlor/Ext/ProjectView/MailPackage/autoconfig.php"#', $auto, $m) === 1, 'autoconfiguration points to the script');
$pattern = '#' . str_replace('#', '\#', $m[1]) . '#';
check(preg_match($pattern, '/mail/config-v1.1.xml') === 1 && preg_match($pattern, '/.well-known/autoconfig/mail/config-v1.1.xml') === 1, 'Thunderbird paths match');
check(preg_match($pattern, '/mail/config-v1.1.xml.bak') === 0 && preg_match($pattern, '/Autoconfiguration.php') === 0, 'other paths do not match');
check(str_contains($auto, 'Require all denied') && str_contains($auto, '<Files "autoconfig.php">'), 'only autoconfig.php is reachable');
check(!preg_match('/[^\x00-\x7f]/', $webmail . $auto), 'Apache lines are ASCII only');
check(MailPackage::pathsValid($paths), 'normal paths are valid');
check(!MailPackage::pathsValid(['roundcube' => '/x" "y'] + $paths), 'path with quotes: invalid');
check(!MailPackage::pathsValid(['socket' => ''] + $paths), 'empty socket: invalid');
check(!MailPackage::pathsValid(['socket' => "/run/x\nInclude /etc/passwd"] + $paths), 'socket with line break: invalid');
check(!MailPackage::pathsValid(['socket' => "/run/x.sock\n"] + $paths), 'socket with trailing line break: invalid');

echo "Mail SNI\n";
$tmp = sys_get_temp_dir() . '/domain-tls-' . getmypid();
mkdir($tmp);
function cert(string $tmp, string $name, int $days): array {
	$conf = $tmp . '/openssl.cnf';
	file_put_contents($conf, "[req]\ndistinguished_name=dn\n[dn]\n[san]\nsubjectAltName=DNS:$name\n");
	$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $conf]);
	$csr = openssl_csr_new(['commonName' => $name], $key, ['config' => $conf, 'req_extensions' => 'san', 'digest_alg' => 'sha256']);
	$crt = openssl_csr_sign($csr, null, $key, $days, ['config' => $conf, 'x509_extensions' => 'san', 'digest_alg' => 'sha256']);
	openssl_x509_export($crt, $chain);
	openssl_pkey_export($key, $pem, null, ['config' => $conf]);
	return ['host' => $name, 'chain' => $chain, 'key' => $pem];
}
$good = cert($tmp, 'mail.customer.example', 90);
$now2 = time();
check(MailSni::validate($good, $now2) === null, 'matching certificate is accepted');
check(MailSni::validate(['host' => 'mail.other.example'] + $good, $now2) !== null, 'certificate for another name: rejected');
check(MailSni::validate(['key' => cert($tmp, 'mail.customer.example', 90)['key']] + $good, $now2) !== null, 'foreign key: rejected');
check(MailSni::validate($good, $now2 + 91 * 86400) !== null, 'expired: rejected');
check(MailSni::validate(['host' => "mail.customer.example\n}"] + $good, $now2) !== null, 'name with control characters: rejected');

$conf24 = MailSni::dovecotConf(['mail.customer.example'], '/etc/ssl/x', true);
check(str_contains($conf24, "local_name mail.customer.example {\n  ssl_server_cert_file = /etc/ssl/x/mail.customer.example.crt\n  ssl_server_key_file = /etc/ssl/x/mail.customer.example.key\n}"), 'Dovecot 2.4: local_name with ssl_server_*_file');
check(!str_contains($conf24, '<'), 'Dovecot 2.4: no "<" file prefix');
check(str_contains(MailSni::dovecotConf(['mail.customer.example'], '/etc/ssl/x', false), 'ssl_cert = </etc/ssl/x/mail.customer.example.crt'), 'Dovecot 2.3: ssl_cert = <');
check(str_contains(MailSni::postfixMap(['mail.customer.example'], '/etc/ssl/x'), "\nmail.customer.example /etc/ssl/x/mail.customer.example.key /etc/ssl/x/mail.customer.example.crt\n"), 'Postfix: name, key, chain');

// Broken chain: intermediate certificate of a foreign authority appended.
$foreign = cert($tmp, 'ca.other.example', 90);
check(MailSni::validate(['chain' => $good['chain'] . $foreign['chain']] + $good, $now2) !== null, 'chain whose links do not fit together: rejected');
check(MailSni::validate(['chain' => $good['chain'] . "-----BEGIN CERTIFICATE-----\nbroken\n-----END CERTIFICATE-----\n"] + $good, $now2) !== null, 'chain with unreadable link: rejected');

Settings::$values['system.le_renew_services'] = 'postfix,dovecot24,proftpd';
mkdir("$tmp/postfix");
mkdir("$tmp/dovecot");
$o = [
	'dir' => "$tmp/certs", 'postfix_map' => "$tmp/postfix/sni", 'dovecot_conf' => "$tmp/dovecot/98-sni.conf",
	'postmap' => 'touch ' . escapeshellarg("$tmp/postfix/sni.db") . ' #', 'postconf' => 'printf "" #',
	'postfix_reload' => 'true', 'doveconf' => 'true', 'dovecot_reload' => 'true',
];
$r = MailSni::run($o, [], $now2);
check(!file_exists("$tmp/postfix/sni") && !file_exists("$tmp/dovecot/98-sni.conf") && $r['postfix'] === 'unchanged', 'without certificates: mail configuration stays untouched');
touch("$tmp/postfix/sni.db");
chmod("$tmp/postfix/sni.db", 0644);
$r = MailSni::run($o, [$good], $now2);
check($r['postfix'] === 'reloaded' && $r['dovecot'] === 'reloaded' && $r['hosts'] === 1 && $r['native'] === false, 'first certificate: both services reloaded');
$mode = static fn($f) => substr(sprintf('%o', fileperms($f)), -4);
check($mode("$tmp/certs/mail.customer.example.key") === '0600', 'key readable by root only');
check($mode("$tmp/postfix/sni.db") === '0600' && $mode("$tmp/postfix/sni") === '0600', 'Postfix table (contains the keys) readable by root only');
check($mode("$tmp/certs/state.json") === '0600', 'state file readable by root only');
$r = MailSni::run($o, [$good], $now2);
check($r['postfix'] === 'unchanged' && $r['dovecot'] === 'unchanged', 'second run without change: nothing reloaded');
$renewed = cert($tmp, 'mail.customer.example', 90);
$r = MailSni::run($o, [$renewed], $now2);
check($r['postfix'] === 'reloaded' && $r['dovecot'] === 'reloaded', 'renewed certificate: reload');

$before = file_get_contents("$tmp/dovecot/98-sni.conf");
$second = cert($tmp, 'mail.second.example', 90);
$r = MailSni::run(['doveconf' => 'false'] + $o, [$second], $now2);
check(str_starts_with($r['dovecot'], 'ERROR') && file_get_contents("$tmp/dovecot/98-sni.conf") === $before, 'doveconf fails: old file comes back');
check(is_file("$tmp/certs/mail.customer.example.crt"), 'doveconf fails: files of the old configuration stay');
$r = MailSni::run(['doveconf' => 'false'] + $o, [$second], $now2 + 60);
check($r['dovecot'] === 'unchanged' && $r['postfix'] === 'unchanged', 'failed state: no new attempt in the next minute');
$r = MailSni::run(['doveconf' => 'false'] + $o, [$second], $now2 + 3600);
check(str_starts_with($r['dovecot'], 'ERROR'), 'failed state: new attempt after an hour');
$r = MailSni::run($o, [$second], $now2 + 7300);
check($r['dovecot'] === 'reloaded' && !is_file("$tmp/certs/mail.customer.example.crt"), 'works again: loaded, old files removed');

$r = MailSni::run(['postconf' => 'echo hash:/etc/postfix/other #'] + $o, [$second, cert($tmp, 'mail.third.example', 90)], $now2);
check(str_starts_with($r['postfix'], 'ERROR: tls_server_sni_maps is already set'), 'foreign tls_server_sni_maps is never overwritten');

// Failure before postmap: no .db, still retried only hourly.
$foreignValue = ['postconf' => 'echo hash:/etc/postfix/other #'] + $o;
@unlink("$tmp/postfix/sni.db");
$fourth = cert($tmp, 'mail.fourth.example', 90);
$r = MailSni::run($foreignValue, [$second, $fourth], $now2 + 3800);
$r2 = MailSni::run($foreignValue, [$second, $fourth], $now2 + 3860);
check(str_starts_with($r['postfix'], 'ERROR') && $r2['postfix'] === 'unchanged', 'failure without .db: no new attempt in the next minute');
$r = MailSni::run($o, [$second], $now2 + 3900);
unlink("$tmp/postfix/sni.db");
$r = MailSni::run($o, [$second], $now2 + 4000);
check($r['postfix'] === 'reloaded' && is_file("$tmp/postfix/sni.db"), 'missing Postfix table: rebuilt immediately');

Settings::$values['system.le_renew_services'] = 'postfix';
$o2 = ['postconf' => 'echo ' . escapeshellarg("hash:$tmp/postfix/sni") . ' #'] + $o;
$r = MailSni::run($o2, [$second], $now2 + 4100);
check($r['dovecot'] === 'removed' && !is_file("$tmp/dovecot/98-sni.conf"), 'Dovecot dropped from Froxlor\'s list: file removed');
Settings::$values['system.le_renew_services'] = 'dovecot24';
$r = MailSni::run($o2, [$second], $now2 + 4200);
check($r['postfix'] === 'removed' && !is_file("$tmp/postfix/sni") && !is_file("$tmp/postfix/sni.db"), 'Postfix dropped from Froxlor\'s list: table removed');
Settings::$values['system.le_renew_services'] = 'postfix,dovecot24';
$r = MailSni::run(['postconf' => 'echo ' . escapeshellarg("hash:/etc/other, hash:$tmp/postfix/sni") . ' #'] + $o, [$second], $now2 + 4300);
Settings::$values['system.le_renew_services'] = 'dovecot24';
$r = MailSni::run(['postconf' => 'echo ' . escapeshellarg("hash:/etc/other, hash:$tmp/postfix/sni") . ' #'] + $o, [], $now2 + 4400);
check(str_starts_with($r['postfix'], 'ERROR') && is_file("$tmp/postfix/sni") && is_file("$tmp/certs/mail.second.example.key"), 'table still inside a foreign value: stays, keys too');
$r = MailSni::run(['postconf' => 'echo ' . escapeshellarg("hash:/etc/other, hash:$tmp/postfix/sni") . ' #'] + $o, [], $now2 + 4460);
check($r['postfix'] === 'unchanged', 'failed teardown: no new attempt in the next minute');
Settings::$values['system.le_renew_services'] = 'proftpd';
@unlink("$tmp/postfix/sni"); @unlink("$tmp/postfix/sni.db");
$r = MailSni::run($o, [$second], $now2 + 4500);
Settings::$values['system.le_renew_services'] = 'postfix,dovecot24';
$r = MailSni::run(['dovecot_conf' => "$tmp/doesnotexist/98.conf"] + $o, [$second], $now2 + 4600);
check($r['dovecot'] === 'not set up', 'Dovecot directory missing: do not touch, no error');

$r = MailSni::run($o, [], $now2 + 5000);
check($r['hosts'] === 0 && glob("$tmp/certs/*.{key,crt}", GLOB_BRACE) === [] && !str_contains(file_get_contents("$tmp/dovecot/98-sni.conf"), 'local_name'), 'last certificate gone: files removed, configuration empty');

echo "Mail SNI: Froxlor's own SNI (mail.sni_enabled)\n";
check(MailSni::nativeSniActive() === false, 'setting does not exist (Froxlor without native SNI): not active');
Settings::$values['mail.sni_enabled'] = '0';
check(MailSni::nativeSniActive() === false, 'setting exists but is off: not active');
// Set up a live state again, then switch Froxlor's own SNI on.
$r = MailSni::run($o2, [$second], $now2 + 5100);
check(is_file("$tmp/postfix/sni") && is_file("$tmp/dovecot/98-sni.conf") && is_file("$tmp/certs/mail.second.example.key"), 'native SNI off: own SNI is set up');
Settings::$values['mail.sni_enabled'] = '1';
check(MailSni::nativeSniActive() === true, 'setting is on: active');
// Froxlor's SNI owns tls_server_sni_maps meanwhile: ours must not touch it, only delete our table.
$froxlorOwned = ['postconf' => 'echo hash:/etc/postfix/froxlor-sni #', 'doveconf' => 'false'] + $o;
$r = MailSni::run($froxlorOwned, [$second, $fourth], $now2 + 5200);
check($r['native'] === true && $r['postfix'] === 'removed' && $r['dovecot'] === 'removed', 'native SNI on: own Postfix table and Dovecot file removed');
check(!is_file("$tmp/postfix/sni") && !is_file("$tmp/postfix/sni.db") && !is_file("$tmp/dovecot/98-sni.conf") && !is_dir("$tmp/certs"), 'native SNI on: keys and state removed, nothing written');
$r = MailSni::run($froxlorOwned, [$second], $now2 + 5260);
check($r['postfix'] === 'not set up' && !is_dir("$tmp/certs") && !is_file("$tmp/postfix/sni"), 'native SNI on: later runs write nothing');
// Teardown while our table is still in a foreign value: kept, retried hourly.
Settings::$values['mail.sni_enabled'] = '0';
$r = MailSni::run($o2, [$second], $now2 + 5300);
Settings::$values['mail.sni_enabled'] = '1';
$mixed = ['postconf' => 'echo ' . escapeshellarg("hash:/etc/other, hash:$tmp/postfix/sni") . ' #'] + $o;
$r = MailSni::run($mixed, [$second], $now2 + 5400);
check(str_starts_with($r['postfix'], 'ERROR') && is_file("$tmp/postfix/sni") && is_file("$tmp/certs/mail.second.example.key") && !is_file("$tmp/dovecot/98-sni.conf"), 'native SNI on, table in a foreign value: table and keys stay, Dovecot file goes');
$r = MailSni::run($mixed, [$second], $now2 + 5460);
check($r['postfix'] === 'unchanged', 'native SNI on, failed teardown: no new attempt in the next minute');
$r = MailSni::run($o2, [$second], $now2 + 5400 + 3600);
check($r['postfix'] === 'removed' && !is_dir("$tmp/certs"), 'native SNI on, our value again after an hour: removed');
Settings::$values['mail.sni_enabled'] = '0';

echo "Mail SNI: teardown (uninstall)\n";
$r = MailSni::run($o2, [$second], $now2 + 9100);
$t = MailSni::teardown($o2);
check($t === ['postfix' => 'removed', 'dovecot' => 'removed', 'files' => 'removed'], 'teardown removes table, Dovecot file and keys');
check(!is_file("$tmp/postfix/sni") && !is_file("$tmp/postfix/sni.db") && !is_file("$tmp/dovecot/98-sni.conf") && !is_dir("$tmp/certs"), 'teardown: nothing of ours is left');
check(MailSni::teardown($o2) === ['postfix' => 'not set up', 'dovecot' => 'not set up', 'files' => 'not set up'], 'teardown is idempotent');
exec('rm -rf ' . escapeshellarg($tmp));

echo "Autoconfiguration\n";
check(Autoconfiguration::domainFromHost('autoconfig.customer.example') === 'customer.example', 'autoconfig.customer.example');
check(Autoconfiguration::domainFromHost('Autodiscover.Customer.EXAMPLE:443') === 'customer.example', 'upper case and port');
check(Autoconfiguration::domainFromHost('autoconfig.xn--mller-kva.example') === 'xn--mller-kva.example', 'punycode domain');
foreach (['webmail.customer.example', 'autoconfig.', 'autoconfig.customer', 'autoconfig.customer.example/../x', 'www.autoconfig.customer.example', "autoconfig.customer.example\r\nX: y"] as $wrong) {
	check(Autoconfiguration::domainFromHost($wrong) === null, 'rejected: ' . json_encode($wrong));
}
$request = '<?xml version="1.0"?><Autodiscover><Request><EMailAddress>Anna@Customer.example</EMailAddress></Request></Autodiscover>';
check(Autoconfiguration::addressFromRequest($request, 'customer.example') === 'anna@customer.example', 'address from Outlook request');
check(Autoconfiguration::addressFromRequest($request, 'other.example') === null, 'address of a foreign domain is not mirrored');
check(Autoconfiguration::addressFromRequest(str_repeat('x', 9000) . $request, 'customer.example') === null, 'oversized request is not read');
$tb = simplexml_load_string(Autoconfiguration::thunderbird('customer.example'));
check($tb !== false && (string)$tb->emailProvider->incomingServer[0]->hostname === 'mail.customer.example', 'Thunderbird XML valid, server mail.customer.example');
$ol = simplexml_load_string(Autoconfiguration::outlook('customer.example', 'anna@customer.example'));
check($ol !== false && str_contains(Autoconfiguration::outlook('customer.example', 'anna@customer.example'), '<LoginName>anna@customer.example</LoginName>'), 'Outlook XML valid, with login name');
check(simplexml_load_string(Autoconfiguration::outlook('customer.example', null)) !== false, 'Outlook XML without address valid');

exit($failures ? 1 : 0);
