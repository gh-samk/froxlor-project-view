<?php

namespace Froxlor\Ext\ProjectView\Cron;

use Froxlor\Cron\FroxlorCron;
use Froxlor\Database\Database;
use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\ProjectStats;
use Froxlor\Ext\ProjectView\ResourceMapper;
use Froxlor\Ext\ProjectView\WordpressCli;
use Froxlor\Ext\ProjectView\WordpressJobs;
use Froxlor\Ext\ProjectView\WordpressScanner;
use Froxlor\FroxlorLogger;

/**
 * Daily scan for WordPress installations.
 *
 * This cron is registered exclusively through the cronjobs_run table (see
 * migrations/001_init.sql). Froxlor reads cronclass from there dynamically
 * (lib/Froxlor/Cli/MasterCron.php) — there is no registration in code that
 * would have to be patched.
 *
 * After the scan it queues a check job for every installation whose last
 * check is older than 20 hours. That way available updates show up in
 * Project View daily without anyone having to click.
 */
class WordpressScanCron extends FroxlorCron
{
	private const CHECK_VALID_FOR = 72000;

	public static function run()
	{
		// Messages deliberately plain ASCII: Froxlor's logger mangles non-ASCII
		// characters in the cron output.
		$logger = FroxlorLogger::getInstanceOf();
		$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'Project View: WordPress scan started');

		// Target version first, so the outdated flag matches the same run. If
		// fetching fails, the scan carries on anyway.
		$latest = WordpressScanner::refreshLatestVersion();
		if ($latest === null) {
			$logger->logAction(
				FroxlorLogger::CRON_ACTION,
				LOG_WARNING,
				'Project View: current WordPress version not available, scan continues without outdated flag'
			);
		}

		$result = WordpressScanner::scanAll();
		if ($result['capped'] > 0) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, sprintf(
				'Project View: %d projects have more than %d WordPress installations, only the first %d were recorded',
				$result['capped'],
				WordpressScanner::MAX_INSTALLS_PER_PROJECT,
				WordpressScanner::MAX_INSTALLS_PER_PROJECT
			));
		}
		if ($result['skipped'] > 0) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, sprintf(
				'Project View: %d projects skipped because their document root lies behind a symbolic link',
				$result['skipped']
			));
		}

		// Clean up orphaned assignments while at it: Froxlor does not know our
		// mapping table and deletes nothing there when a database or an FTP
		// account is removed.
		$pruned = ResourceMapper::pruneOrphans();

		// Recompute disk usage per project (du as root plus database sizes).
		// Runs here because the cron walks the customer directories as root
		// anyway, and the computation does not belong in a page request.
		$measured = 0;
		try {
			$measured = ProjectStats::computeAll();
		} catch (\Throwable $e) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, 'Project View: disk usage computation failed: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));
		}

		$queued = self::queueChecks();

		$logger->logAction(
			FroxlorLogger::CRON_ACTION,
			LOG_NOTICE,
			sprintf(
				'Project View: WordPress scan finished - %d projects scanned, %d installations found, %d vanished removed, %d orphaned assignments cleaned up, %d projects measured, %d check jobs queued',
				$result['scanned'],
				$result['found'],
				$result['removed'],
				$pruned,
				$measured,
				$queued
			)
		);
	}

	private static function queueChecks(): int
	{
		// Without WP-CLI every check would fail — then rather queue none at all
		// than a series of error messages every day.
		if (WordpressCli::wpcliPath() === null) {
			return 0;
		}

		$stmt = Database::prepare("
			SELECT `id`, `domainid` FROM `ext_projectview_wp_installs` WHERE `checked_at` < :limit
		");
		Database::pexecute($stmt, ['limit' => time() - self::CHECK_VALID_FOR]);

		$queued = 0;
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $install) {
			// Background job: admin jobs queued in the panel run first.
			if (WordpressJobs::enqueue((int)$install['id'], (int)$install['domainid'], 'check', Lang::t('Daily scan'), true)) {
				$queued++;
			}
		}

		return $queued;
	}
}
