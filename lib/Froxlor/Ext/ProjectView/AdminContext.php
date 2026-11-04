<?php

namespace Froxlor\Ext\ProjectView;

use Froxlor\Database\Database;
use RuntimeException;

/**
 * User context for Froxlor API calls made from cron.
 *
 * There is no login in cron. The calls therefore run on behalf of the admin
 * the customer belongs to — exactly as if that admin had clicked in the
 * panel. Froxlor's permission checks (quotas, own customers) apply
 * unchanged. Built the same way as Froxlor's Cli/CliCommand::getUserByName().
 */
final class AdminContext
{
	private static array $cache = [];

	public static function forCustomer(int $customerid): array
	{
		if (!isset(self::$cache[$customerid])) {
			$stmt = Database::prepare("
				SELECT `a`.* FROM `" . TABLE_PANEL_ADMINS . "` `a`
				JOIN `" . TABLE_PANEL_CUSTOMERS . "` `c` ON `c`.`adminid` = `a`.`adminid`
				WHERE `c`.`customerid` = :id
			");
			$admin = Database::pexecute_first($stmt, ['id' => $customerid]);
			if (!$admin) {
				throw new RuntimeException(Lang::t('No admin found for customer %d.', $customerid));
			}
			$admin['adminsession'] = 1;
			self::$cache[$customerid] = $admin;
		}

		return self::$cache[$customerid];
	}
}
