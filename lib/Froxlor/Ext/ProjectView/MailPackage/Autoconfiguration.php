<?php

namespace Froxlor\Ext\ProjectView\MailPackage;

/**
 * Responses for the automatic setup of mail clients.
 *
 * - Thunderbird (and many others) request
 *   https://autoconfig.<domain>/mail/config-v1.1.xml.
 * - Outlook POSTs a small XML request to
 *   https://autodiscover.<domain>/autodiscover/autodiscover.xml.
 *
 * Both get the same content: mail.<domain> with IMAP 993, POP3 995 and
 * SMTP 465 (SSL) or 587 (STARTTLS), login with the full address.
 *
 * Runs without Froxlor and without a database in the server's pool. The
 * domain comes from the host name; a vhost exists only for real mail domains.
 */
final class Autoconfiguration
{
	/** An Outlook request is never read beyond this size. */
	public const MAX_REQUEST = 8192;

	/** Domain from the Host header, or null if it does not match. */
	public static function domainFromHost(string $host): ?string
	{
		$host = strtolower(trim($host));
		$host = preg_replace('/:\d+$/', '', $host);
		$host = rtrim($host, '.');
		if (!preg_match('/^(autoconfig|autodiscover)\.((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63})$/', $host, $m)) {
			return null;
		}

		return $m[2];
	}

	/** E-mail address from an Outlook request, only if it belongs to the domain. */
	public static function addressFromRequest(string $xml, string $domain): ?string
	{
		if (strlen($xml) > self::MAX_REQUEST || !preg_match('#<EMailAddress>\s*([^<\s]+)\s*</EMailAddress>#i', $xml, $m)) {
			return null;
		}
		$address = strtolower($m[1]);
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || !str_ends_with($address, '@' . $domain)) {
			return null;
		}

		return $address;
	}

	public static function thunderbird(string $domain): string
	{
		$d = self::xml($domain);
		$mail = self::xml('mail.' . $domain);

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
  <emailProvider id="{$d}">
    <domain>{$d}</domain>
    <displayName>{$d}</displayName>
    <displayShortName>{$d}</displayShortName>
    <incomingServer type="imap">
      <hostname>{$mail}</hostname>
      <port>993</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <incomingServer type="pop3">
      <hostname>{$mail}</hostname>
      <port>995</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>{$mail}</hostname>
      <port>465</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
    <outgoingServer type="smtp">
      <hostname>{$mail}</hostname>
      <port>587</port>
      <socketType>STARTTLS</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
  </emailProvider>
</clientConfig>

XML;
	}

	public static function outlook(string $domain, ?string $address): string
	{
		$mail = self::xml('mail.' . $domain);
		$login = $address !== null ? "\n        <LoginName>" . self::xml($address) . '</LoginName>' : '';

		return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
      <Protocol>
        <Type>IMAP</Type>
        <Server>{$mail}</Server>
        <Port>993</Port>
        <DomainRequired>off</DomainRequired>{$login}
        <SPA>off</SPA>
        <SSL>on</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$mail}</Server>
        <Port>465</Port>
        <DomainRequired>off</DomainRequired>{$login}
        <SPA>off</SPA>
        <Encryption>SSL</Encryption>
        <AuthRequired>on</AuthRequired>
        <UsePOPAuth>off</UsePOPAuth>
        <SMTPLast>off</SMTPLast>
      </Protocol>
    </Account>
  </Response>
</Autodiscover>

XML;
	}

	private static function xml(string $text): string
	{
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
