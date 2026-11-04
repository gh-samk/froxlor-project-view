<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use PDO;

/**
 * Optional hook for the separate Backup extension.
 *
 * The Backup extension asks for it with class_exists() and works the same
 * without it; Project View itself never calls it. It tells Backup which
 * databases and FTP accounts Project View assigned to which project, so a
 * project restore can include them.
 */
final class BackupIntegration
{
	/**
	 * Assignments of one customer's databases and FTP accounts to projects.
	 *
	 * Only rows where the resource AND the project really belong to that
	 * customer are returned — the mapping table is the extension's own and
	 * Froxlor does not keep it consistent.
	 *
	 * @return list<array{domainid:int, type:string, id:int}> type: database | ftp
	 */
	public static function resourceAssignments(int $customerId): array
	{
		$stmt = Database::prepare("
			SELECT `m`.`domainid`, `m`.`resource_type`, `m`.`resource_id`
			FROM `ext_projectview_resources` `m`
			INNER JOIN `" . TABLE_PANEL_DOMAINS . "` `d`
			        ON `d`.`id` = `m`.`domainid` AND `d`.`customerid` = :c1
			LEFT JOIN `" . TABLE_PANEL_DATABASES . "` `db`
			       ON `m`.`resource_type` = 'database' AND `db`.`id` = `m`.`resource_id` AND `db`.`customerid` = :c2
			LEFT JOIN `" . TABLE_FTP_USERS . "` `f`
			       ON `m`.`resource_type` = 'ftp' AND `f`.`id` = `m`.`resource_id` AND `f`.`customerid` = :c3
			WHERE `db`.`id` IS NOT NULL OR `f`.`id` IS NOT NULL
			ORDER BY `m`.`domainid`, `m`.`resource_type`, `m`.`resource_id`
		");
		Database::pexecute($stmt, ['c1' => $customerId, 'c2' => $customerId, 'c3' => $customerId]);

		$result = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$result[] = [
				'domainid' => (int)$row['domainid'],
				'type' => (string)$row['resource_type'],
				'id' => (int)$row['resource_id'],
			];
		}

		return $result;
	}
}
