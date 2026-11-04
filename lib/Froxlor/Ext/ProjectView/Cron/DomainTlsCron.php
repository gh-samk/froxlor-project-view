<?php

namespace Froxlor\Ext\ProjectView\Cron;

use Froxlor\Cron\FroxlorCron;
use Froxlor\Ext\ProjectView\MailPackage;
use Froxlor\Ext\ProjectView\MailSni;
use Froxlor\Ext\ProjectView\SubdomainSsl;
use Froxlor\FroxlorLogger;
use Froxlor\Settings;

/**
 * Certificates for subdomains and the mail package.
 *
 * Registered in cronjobs_run with interval "1 MINUTE" (see migrations).
 * Three independent steps; if one fails, the others still run:
 * 1. Turn on Let's Encrypt for new subdomains (SubdomainSsl)
 * 2. Create and maintain the mail package (MailPackage)
 * 3. Hand the certificates of the mail. subdomains to Postfix and Dovecot (MailSni)
 *
 * The certificates themselves are issued by Froxlor's Let's Encrypt cron.
 * Whatever is turned on here therefore gets its certificate in that cron's
 * next run, and SNI picks it up one run later.
 *
 * Messages to Froxlor's logger are plain ASCII, see WordpressJobCron.
 */
class DomainTlsCron extends FroxlorCron
{
	public static function run()
	{
		$logger = FroxlorLogger::getInstanceOf();
		$log = static function (int $level, string $text) use ($logger) {
			// Froxlor's logger mangles umlauts; messages from the API (and
			// translated texts) may contain them.
			$text = strtr($text, [
				"\u{e4}" => 'ae', "\u{f6}" => 'oe', "\u{fc}" => 'ue', "\u{c4}" => 'Ae', "\u{d6}" => 'Oe', "\u{dc}" => 'Ue', "\u{df}" => 'ss',
				"\r" => ' ', "\n" => ' ',
			]);
			$logger->logAction(FroxlorLogger::CRON_ACTION, $level, 'Project View: ' . $text);
		};

		if ((string)Settings::Get('ext_projectview.ssl_subdomains_auto') === '1' && (string)Settings::Get('system.leenabled') === '1') {
			try {
				$c = SubdomainSsl::run();
				if ($c['enabled'] + $c['failed'] + $c['given_up'] > 0) {
					$log(LOG_NOTICE, sprintf(
						'Lets Encrypt for subdomains - %d turned on, %d waiting for DNS, %d given up, %d errors',
						$c['enabled'], $c['waiting'], $c['given_up'], $c['failed']
					));
				}
			} catch (\Throwable $e) {
				$log(LOG_WARNING, 'Lets Encrypt for subdomains failed: ' . $e->getMessage());
			}
		}

		try {
			$c = MailPackage::run();
			if ($c['created'] + $c['maintained'] + $c['failed'] > 0) {
				$log(LOG_NOTICE, sprintf(
					'Mail package - %d subdomains created, %d vhost settings renewed, %d errors',
					$c['created'], $c['maintained'], $c['failed']
				));
			}
		} catch (\Throwable $e) {
			$log(LOG_WARNING, 'Mail package failed: ' . $e->getMessage());
		}

		// Postfix and Dovecot read their configuration as root; without root
		// (e.g. a test invocation) there is nothing to do here.
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			return;
		}
		try {
			$c = MailSni::run();
			$notes = json_encode($c['skipped'], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
			if ($notes !== (string)Settings::Get('ext_projectview.mail_sni_notes')) {
				Settings::Set('ext_projectview.mail_sni_notes', $notes);
				foreach ($c['skipped'] as $host => $reason) {
					$log(LOG_WARNING, 'Mail SNI without ' . $host . ': ' . $reason);
				}
			}
			foreach (['postfix', 'dovecot'] as $service) {
				if (!in_array($c[$service], ['unchanged', 'not set up'], true)) {
					$log(
						str_starts_with($c[$service], 'ERROR') ? LOG_ERR : LOG_NOTICE,
						$c['native']
							? sprintf('Mail SNI %s: Froxlor\'s own mail SNI (mail.sni_enabled) is on, standing down: %s', $service, $c[$service])
							: sprintf('Mail SNI %s (%d names): %s', $service, $c['hosts'], $c[$service])
					);
				}
			}
		} catch (\Throwable $e) {
			$log(LOG_WARNING, 'Mail SNI failed: ' . $e->getMessage());
		}
	}
}
