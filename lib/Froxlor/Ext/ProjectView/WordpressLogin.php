<?php

namespace Froxlor\Ext\ProjectView;

use RuntimeException;

/**
 * One-time login into WordPress, without a password.
 *
 * Design: one Ed25519 key pair per installation. The secret key stays in the
 * Froxlor database; only a small must-use plugin with the public key goes
 * into the WordPress installation.
 *
 * Why a signature and not a shared secret: the plugin sits readable in the
 * web directory. If it held an HMAC secret, anyone able to read the file — on
 * some servers another customer, too — could forge valid login links. A
 * public key reveals nothing.
 *
 * A link is valid for 60 seconds and exactly once, and it only logs in users
 * who have the manage_options capability in WordPress.
 */
class WordpressLogin
{
	/** File name of the must-use plugin in wp-content/mu-plugins/. */
	public const FILE = 'froxlor-project-view-login.php';

	/** Query parameter the must-use plugin listens on. */
	public const QUERY_PARAMETER = 'froxlor_project_view_login';

	private const VALIDITY = 60;

	/**
	 * @return array{0:string, 1:string} secret and public key, Base64
	 */
	public static function keyPair(): array
	{
		$pair = sodium_crypto_sign_keypair();

		return [
			base64_encode(sodium_crypto_sign_secretkey($pair)),
			base64_encode(sodium_crypto_sign_publickey($pair)),
		];
	}

	/**
	 * Creates a signed one-time link.
	 *
	 * $allowedHosts are the customer's host names. The host of the siteurl
	 * reported by WordPress must be among them — otherwise "Log in" would send
	 * the admin to a host the customer can set freely in their database
	 * (phishing, and since the jump starts from the panel origin, a target
	 * for GET-CSRF against the panel itself).
	 *
	 * @param string[] $allowedHosts
	 * @throws RuntimeException
	 */
	public static function link(array $install, string $secretB64, string $user, array $allowedHosts): string
	{
		$secret = base64_decode($secretB64, true);
		if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new RuntimeException(Lang::t('Autologin is not set up for this installation.'));
		}
		$siteurl = trim((string)($install['siteurl'] ?? ''));
		if (!preg_match('#^https?://#i', $siteurl)) {
			throw new RuntimeException(Lang::t('The website address is not known yet — run “Check now” first.'));
		}
		// The redirect target is rebuilt from strictly validated parts — never
		// the raw value from the customer's database.
		$parts = self::parseSiteUrl($siteurl);
		if ($parts === null) {
			throw new RuntimeException(Lang::t('The website address is invalid.'));
		}
		if ($parts['scheme'] !== 'https') {
			throw new RuntimeException(Lang::t('Autologin needs a website address with https, so that the link and the login cookie are not sent unencrypted.'));
		}
		self::checkHost($parts['host'], $allowedHosts);
		$base = rtrim(self::buildSiteUrl($parts), '/');

		$payload = self::b64url(json_encode([
			'i' => (int)$install['id'],
			'u' => $user,
			'e' => time() + self::VALIDITY,
			'n' => bin2hex(random_bytes(16)),
		]));
		$signature = self::b64url(sodium_crypto_sign_detached($payload, $secret));

		return $base . '/?' . self::QUERY_PARAMETER . '=' . $payload . '.' . $signature;
	}

	/**
	 * Source code of the must-use plugin for one installation.
	 *
	 * Signature verification uses sodium_crypto_sign_verify_detached.
	 * WordPress has shipped a fallback (sodium_compat) since 5.2 in case the
	 * PHP extension is missing — so the plugin runs on every current WordPress.
	 *
	 * The error page is shown to whoever opens the link in WordPress, so it
	 * follows the language of the website, not of the panel. Both language
	 * versions are therefore embedded and the plugin picks one at runtime.
	 */
	public static function muPlugin(int $installId, string $publicB64): string
	{
		if (!preg_match('#^[A-Za-z0-9+/]{43}=$#', $publicB64)) {
			throw new RuntimeException(Lang::t('Invalid public key.'));
		}

		$template = <<<'PHP'
<?php
/**
 * Plugin Name: __PLUGIN_NAME__
 * Description: __PLUGIN_DESCRIPTION__
 * Version: 1.0
 */

defined('ABSPATH') || exit;

add_action('init', static function () {
	if (!isset($_GET['__PARAMETER__']) || !is_string($_GET['__PARAMETER__'])) {
		return;
	}

	$abort = static function () {
		// The page is seen by whoever opens the link in WordPress: language of the website.
		$german = strncmp(determine_locale(), 'de', 2) === 0;
		wp_die(
			$german ? __MESSAGE_DE__ : __MESSAGE_EN__,
			$german ? __TITLE_DE__ : __TITLE_EN__,
			['response' => 403]
		);
	};

	$decode = static function (string $text) {
		return base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
	};

	$parts = explode('.', $_GET['__PARAMETER__'], 2);
	if (count($parts) !== 2) {
		$abort();
	}
	[$payload, $signature] = $parts;

	$sig = $decode($signature);
	if ($sig === false || strlen($sig) !== 64
		|| !sodium_crypto_sign_verify_detached($sig, $payload, base64_decode('__PUBLIC_KEY__', true))) {
		$abort();
	}

	$data = json_decode((string)$decode($payload), true);
	if (!is_array($data) || ($data['i'] ?? null) !== __INSTALLATION__
		|| (int)($data['e'] ?? 0) < time() || empty($data['n'])) {
		$abort();
	}

	// Every link exactly once. add_option fails if the name already exists —
	// that is atomic (unique key option_name), unlike get_transient/
	// set_transient, where two parallel requests could both get through.
	// autoload 'no', so the markers are not loaded on every page view.
	$marker = '__PARAMETER___' . md5((string)$data['n']);
	if (!add_option($marker, time(), '', 'no')) {
		$abort();
	}
	// Clean up old markers (older than one hour) along the way, so the
	// options table does not grow forever.
	global $wpdb;
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
		$wpdb->esc_like('__PARAMETER___') . '%',
		time() - HOUR_IN_SECONDS
	));

	$user = get_user_by('login', (string)($data['u'] ?? ''));
	if (!$user || !user_can($user, 'manage_options')) {
		$abort();
	}

	wp_set_current_user($user->ID);
	wp_set_auth_cookie($user->ID, false, is_ssl());
	do_action('wp_login', $user->user_login, $user);
	wp_safe_redirect(admin_url());
	exit;
}, 1);

PHP;

		$message = 'This login link is invalid, has expired or has already been used.';
		$title = 'Login not possible';

		return strtr($template, [
			'__PUBLIC_KEY__' => $publicB64,
			'__INSTALLATION__' => (string)$installId,
			'__PARAMETER__' => self::QUERY_PARAMETER,
			'__MESSAGE_EN__' => var_export($message, true),
			'__TITLE_EN__' => var_export($title, true),
			'__MESSAGE_DE__' => var_export(self::german('This login link is invalid, has expired or has already been used.'), true),
			'__TITLE_DE__' => var_export(self::german('Login not possible'), true),
			'__PLUGIN_NAME__' => Lang::t('Project View – one-time login'),
			'__PLUGIN_DESCRIPTION__' => Lang::t('Short-lived one-time login from the Froxlor Project View. Managed from there — do not edit by hand, choose “Remove autologin” in the Project View instead.'),
		]);
	}

	/**
	 * The German version of a text, regardless of the current language. The
	 * must-use plugin carries both versions (see muPlugin()).
	 */
	private static function german(string $en): string
	{
		$current = Lang::iso();
		Lang::set('de');
		try {
			return Lang::t($en);
		} finally {
			Lang::set($current);
		}
	}

	/**
	 * A WordPress site address, strictly validated and rebuilt — or null.
	 *
	 * The siteurl comes from the customer's database and ends up in links and
	 * in the autologin redirect. Browsers and parse_url() disagree on unusual
	 * URLs (http://evil.example\@customer.example, backslashes, user info),
	 * so instead of parsing leniently only one plain form is accepted:
	 * http(s)://host[:port][/path] with an ASCII host (IDN hosts are converted
	 * to punycode first), no user info, no query, no fragment, no
	 * backslashes, whitespace or control characters. The URL returned is
	 * assembled from the validated parts.
	 */
	public static function normalizeSiteUrl(?string $url): ?string
	{
		$parts = self::parseSiteUrl($url);

		return $parts === null ? null : self::buildSiteUrl($parts);
	}

	/**
	 * @return array{scheme:string, host:string, port:?int, path:string}|null
	 */
	private static function parseSiteUrl(?string $url): ?array
	{
		$url = trim((string)$url);
		if ($url === '' || strlen($url) > 2048) {
			return null;
		}
		if (!preg_match('#^(https?)://([^/:]+)(?::([0-9]{1,5}))?(/.*)?$#iDs', $url, $m)) {
			return null;
		}
		$host = $m[2];
		if (preg_match('/[\x80-\xff]/', $host)) {
			if (!function_exists('idn_to_ascii')) {
				return null;
			}
			$ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
			if ($ascii === false) {
				return null;
			}
			$host = $ascii;
		}
		$host = strtolower($host);
		if (strlen($host) > 253 || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $host)) {
			return null;
		}
		$port = null;
		if (($m[3] ?? '') !== '') {
			$port = (int)$m[3];
			if ($port < 1 || $port > 65535) {
				return null;
			}
		}
		$path = $m[4] ?? '';
		if (!preg_match('#^(/[A-Za-z0-9._~%/-]*)?$#D', $path)) {
			return null;
		}

		return ['scheme' => strtolower($m[1]), 'host' => $host, 'port' => $port, 'path' => $path];
	}

	private static function buildSiteUrl(array $parts): string
	{
		return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] !== null ? ':' . $parts['port'] : '') . $parts['path'];
	}

	/**
	 * The host of the siteurl must be a host name of the customer — exactly or
	 * a subdomain of it (WordPress may live on a subdomain).
	 *
	 * The message deliberately names no host: it is shown in the panel, and
	 * the host is customer-controlled text.
	 *
	 * @param string[] $allowedHosts
	 */
	private static function checkHost(string $host, array $allowedHosts): void
	{
		foreach ($allowedHosts as $allowed) {
			$allowed = strtolower(trim((string)$allowed));
			if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
				return;
			}
		}
		throw new RuntimeException(Lang::t('The address reported by WordPress does not belong to this customer. Autologin was refused.'));
	}

	private static function b64url(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
