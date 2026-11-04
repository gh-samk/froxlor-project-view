<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use InvalidArgumentException;
use PDO;

/**
 * Queue of the WordPress jobs.
 *
 * The panel only creates jobs; they are executed in the Froxlor cron
 * (Cron/WordpressJobCron.php). At most one job runs per installation at a
 * time — two updates on the same site must never overtake each other.
 */
class WordpressJobs
{
	/** Job types with their English labels (translate with typeLabels() or Lang::t()). */
	public const TYPES = [
		'check' => 'Check',
		'backup' => 'Back up',
		'update_all' => 'Update everything',
		'update_core' => 'Update WordPress core',
		'update_plugins' => 'Update plugins',
		'update_themes' => 'Update themes',
		'login_install' => 'Set up autologin',
		'login_remove' => 'Remove autologin',
	];

	/** The job type labels in the current language. */
	public static function typeLabels(): array
	{
		return [
			'check' => Lang::t('Check'),
			'backup' => Lang::t('Back up'),
			'update_all' => Lang::t('Update everything'),
			'update_core' => Lang::t('Update WordPress core'),
			'update_plugins' => Lang::t('Update plugins'),
			'update_themes' => Lang::t('Update themes'),
			'login_install' => Lang::t('Set up autologin'),
			'login_remove' => Lang::t('Remove autologin'),
		];
	}

	/** After this many seconds a running job counts as stuck. */
	private const STALE_AFTER = 1800;

	/** This many jobs per installation are kept in the history. */
	private const HISTORY = 20;

	public static function isType(string $type): bool
	{
		return isset(self::TYPES[$type]);
	}

	/**
	 * @param bool $background queued by the cron (daily check) rather than by
	 *                         an admin; background jobs only run when no
	 *                         admin job is waiting
	 * @return bool false if the same job is already queued or running for this
	 *              installation — a double click creates nothing twice
	 */
	public static function enqueue(int $installId, int $domainid, string $type, string $createdBy, bool $background = false): bool
	{
		if (!self::isType($type)) {
			throw new InvalidArgumentException(Lang::t('Unknown job: %s', $type));
		}

		$stmt = Database::prepare("
			SELECT COUNT(*) AS `n` FROM `ext_projectview_wp_jobs`
			WHERE `install_id` = :i AND `type` = :t AND `status` IN ('queued', 'running')
		");
		$row = Database::pexecute_first($stmt, ['i' => $installId, 't' => $type]);
		if ((int)($row['n'] ?? 0) > 0) {
			return false;
		}

		$stmt = Database::prepare("
			INSERT INTO `ext_projectview_wp_jobs` (`install_id`, `domainid`, `type`, `status`, `created_by`, `created_at`, `is_background`)
			VALUES (:i, :d, :t, 'queued', :b, :z, :bg)
		");
		Database::pexecute($stmt, ['i' => $installId, 'd' => $domainid, 't' => $type, 'b' => $createdBy, 'z' => time(), 'bg' => $background ? 1 : 0]);

		return true;
	}

	/**
	 * The latest jobs of an installation, newest first.
	 */
	public static function forInstall(int $installId, int $limit = 5): array
	{
		// LIMIT as a number in the string: bound parameters would be quoted as
		// '5' with emulated prepared statements — a syntax error.
		$stmt = Database::prepare("
			SELECT `id`, `type`, `status`, `created_by`, `created_at`, `started_at`, `finished_at`,
			       `backup_path`, `log`
			FROM `ext_projectview_wp_jobs`
			WHERE `install_id` = :i
			ORDER BY `id` DESC
			LIMIT " . max(1, $limit) . "
		");
		Database::pexecute($stmt, ['i' => $installId]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Fetches the next queued job and marks it as running.
	 *
	 * Installations with a job already running are skipped. Marking is bound
	 * to status = 'queued': if two cron processes run at the same time, only
	 * one of them gets the job.
	 *
	 * Order: jobs queued by an admin before the daily background checks, then
	 * the customer whose jobs started least recently, then the oldest job. So
	 * one customer with many installations cannot keep everybody else waiting
	 * — after each job its customer moves to the back of the line. Jobs whose
	 * domain no longer exists are still picked up, so the cron can close them.
	 */
	public static function claimNext(): ?array
	{
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$stmt = Database::prepare("
				SELECT `j`.* FROM `ext_projectview_wp_jobs` `j`
				LEFT JOIN `" . TABLE_PANEL_DOMAINS . "` `d` ON `d`.`id` = `j`.`domainid`
				LEFT JOIN (
				    SELECT `d2`.`customerid`, MAX(`j2`.`started_at`) AS `last_start`
				    FROM `ext_projectview_wp_jobs` `j2`
				    INNER JOIN `" . TABLE_PANEL_DOMAINS . "` `d2` ON `d2`.`id` = `j2`.`domainid`
				    GROUP BY `d2`.`customerid`
				) `c` ON `c`.`customerid` = `d`.`customerid`
				WHERE `j`.`status` = 'queued'
				  AND NOT EXISTS (
				      SELECT 1 FROM `ext_projectview_wp_jobs` `r`
				      WHERE `r`.`install_id` = `j`.`install_id` AND `r`.`status` = 'running'
				  )
				ORDER BY `j`.`is_background` ASC, COALESCE(`c`.`last_start`, 0) ASC, `j`.`id` ASC
				LIMIT 1
			");
			Database::pexecute($stmt);
			$job = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$job) {
				return null;
			}

			$upd = Database::prepare("
				UPDATE `ext_projectview_wp_jobs` SET `status` = 'running', `started_at` = :z
				WHERE `id` = :id AND `status` = 'queued'
			");
			Database::pexecute($upd, ['z' => time(), 'id' => $job['id']]);
			if ($upd->rowCount() === 1) {
				return $job;
			}
		}

		return null;
	}

	public static function finish(int $id, int $installId, bool $ok, string $log, ?string $backupPath): void
	{
		// Keep only the end if a log gets out of hand — that is where it
		// says why it failed.
		if (strlen($log) > 60000) {
			$log = Lang::t('… (truncated)') . "\n" . substr($log, -60000);
		}

		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_jobs`
			SET `status` = :s, `finished_at` = :z, `log` = :l, `backup_path` = :b
			WHERE `id` = :id
		");
		Database::pexecute($stmt, [
			's' => $ok ? 'done' : 'failed',
			'z' => time(),
			'l' => $log,
			'b' => $backupPath,
			'id' => $id,
		]);

		// Limit the history. The double subquery is needed because MySQL does
		// not allow a subquery with LIMIT on the same table in a DELETE.
		$stmt = Database::prepare("
			DELETE FROM `ext_projectview_wp_jobs`
			WHERE `install_id` = :i AND `status` IN ('done', 'failed') AND `id` NOT IN (
				SELECT `id` FROM (
					SELECT `id` FROM `ext_projectview_wp_jobs` WHERE `install_id` = :i2 ORDER BY `id` DESC LIMIT " . self::HISTORY . "
				) AS `keep`
			)
		");
		Database::pexecute($stmt, ['i' => $installId, 'i2' => $installId]);
	}

	/**
	 * Jobs that have been "running" for more than 30 minutes died with their
	 * process. Without this cleanup they would block their installation
	 * forever.
	 */
	public static function failStale(): int
	{
		$stmt = Database::prepare("
			UPDATE `ext_projectview_wp_jobs`
			SET `status` = 'failed', `finished_at` = :z,
			    `log` = CONCAT(COALESCE(`log`, ''), :note)
			WHERE `status` = 'running' AND `started_at` < :limit
		");
		Database::pexecute($stmt, ['z' => time(), 'limit' => time() - self::STALE_AFTER, 'note' => "\n" . Lang::t('Aborted: ran for more than 30 minutes.')]);

		return $stmt->rowCount();
	}
}
