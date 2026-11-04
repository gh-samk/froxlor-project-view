<?php

namespace Froxlor\Ext\ProjectView\Cron;

use Froxlor\Cron\FroxlorCron;
use Froxlor\Database\Database;
use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\WordpressCli;
use Froxlor\Ext\ProjectView\WordpressJobs;
use Froxlor\Ext\ProjectView\WordpressMaintenance;
use Froxlor\FroxlorLogger;

/**
 * Processes the WordPress jobs created in Project View.
 *
 * Registered in cronjobs_run with interval "1 MINUTE" (see migrations/). Froxlor
 * locks per job: if a long update is still running, the next master run
 * skips only this job — Froxlor's own tasks keep running unaffected. Within
 * one run a time budget applies, so a batch of jobs does not hold up the
 * other jobs of the same run.
 *
 * Messages to Froxlor's logger are deliberately plain ASCII: it mangles
 * non-ASCII characters in the cron output.
 */
class WordpressJobCron extends FroxlorCron
{
	/** After this time no new job is started. */
	private const BUDGET = 240;

	public static function run()
	{
		$logger = FroxlorLogger::getInstanceOf();

		$stale = WordpressJobs::failStale();
		if ($stale > 0) {
			$logger->logAction(FroxlorLogger::CRON_ACTION, LOG_WARNING, sprintf('Project View: aborted %d stuck WordPress jobs', $stale));
		}

		$end = time() + self::BUDGET;
		while (time() < $end && ($job = WordpressJobs::claimNext()) !== null) {
			$install = self::installation((int)$job['install_id']);
			if ($install === null) {
				WordpressJobs::finish((int)$job['id'], (int)$job['install_id'], false, Lang::t('ERROR: %s', Lang::t('The installation no longer exists.')), null);
				continue;
			}

			try {
				$cli = WordpressCli::forInstall($install);
				[$ok, $log, $backupPath] = (new WordpressMaintenance($install, $cli))->run((string)$job['type']);
			} catch (\Throwable $e) {
				[$ok, $log, $backupPath] = [false, Lang::t('ERROR: %s', $e->getMessage()), null];
			}

			WordpressJobs::finish((int)$job['id'], (int)$install['id'], $ok, $log, $backupPath);
			// path comes from the scan over customer directories: strip line
			// breaks so they cannot add made-up lines to the cron log.
			$path = str_replace(["\r", "\n"], ' ', (string)$install['path']);
			$logger->logAction(
				FroxlorLogger::CRON_ACTION,
				$ok ? LOG_NOTICE : LOG_WARNING,
				sprintf('Project View: WordPress job #%d (%s) for %s %s', $job['id'], $job['type'], $path, $ok ? 'done' : 'failed')
			);
		}
	}

	private static function installation(int $id): ?array
	{
		$stmt = Database::prepare("SELECT * FROM `ext_projectview_wp_installs` WHERE `id` = :id");
		$row = Database::pexecute_first($stmt, ['id' => $id]);

		return $row ?: null;
	}
}
