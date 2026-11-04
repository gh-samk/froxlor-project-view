<?php

namespace Froxlor\Ext\ProjectView\Callbacks;

use Froxlor\Ext\ProjectView\Lang;
use Froxlor\Ext\ProjectView\ProjectRepository;
use Froxlor\Ext\ProjectView\ProjectStats;
use Froxlor\UI\Panel\UI;

/**
 * Column callbacks for the project overview.
 *
 * Froxlor outputs cell contents with |raw (templates/Froxlor/table/table.html.twig),
 * so escaping happens here. Every value that comes from the database goes
 * through htmlspecialchars.
 */
class Project
{
	private static function esc($value): string
	{
		return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Project name with the customer below, linked to the project page.
	 */
	public static function name(array $attributes): string
	{
		$fields = $attributes['fields'];
		$href = UI::getLinker()->getLink([
			'section' => 'projects',
			'page' => 'detail',
			'id' => $fields['id'],
		]);

		$domain = self::esc($fields['domain_ace'] ?: $fields['domain']);
		$html = '<a href="' . self::esc($href) . '" class="fw-semibold">' . $domain . '</a>';

		if (!empty($fields['deactivated'])) {
			$html .= ' <span class="badge bg-secondary">' . self::esc(Lang::t('deactivated')) . '</span>';
		}

		$html .= '<div class="small text-body-secondary">' . self::esc($fields['customer_label']) . '</div>';

		return $html;
	}

	/**
	 * WordPress column: version plus a flag if outdated.
	 *
	 * Without a known target version deliberately no flag is set — a false
	 * all-clear would be worse than no statement at all.
	 */
	public static function wordpress(array $attributes): string
	{
		$agg = ProjectRepository::aggregateFor((int)$attributes['fields']['id']);

		if ($agg['wp_count'] === 0) {
			return '<span class="text-body-secondary">—</span>';
		}

		$version = $agg['wp_version'] !== null
			? self::esc($agg['wp_version'])
			: '<span class="text-body-secondary">' . self::esc(Lang::t('Version unknown')) . '</span>';

		$html = $agg['wp_outdated']
			? '<span class="badge bg-danger">' . $version . '</span>'
			: '<span class="badge bg-success-subtle text-success-emphasis">' . $version . '</span>';

		if ($agg['wp_count'] > 1) {
			$html .= ' <span class="small text-body-secondary">(' . self::esc(Lang::t('%d installations', (int)$agg['wp_count'])) . ')</span>';
		}

		return $html;
	}

	/**
	 * Compact resource count. One column instead of four, so the overview
	 * stays readable on narrow screens.
	 */
	public static function resources(array $attributes): string
	{
		$agg = ProjectRepository::aggregateFor((int)$attributes['fields']['id']);

		$parts = [
			['fa-solid fa-sitemap', $agg['subdomains'], Lang::t('Subdomains')],
			['fa-solid fa-envelope', $agg['emails'], Lang::t('Email addresses')],
			['fa-solid fa-database', $agg['databases'], Lang::t('Databases')],
			['fa-solid fa-folder-tree', $agg['ftps'], Lang::t('FTP accounts')],
		];

		$html = '<span class="text-nowrap">';
		foreach ($parts as [$icon, $count, $label]) {
			$muted = $count === 0 ? ' text-body-secondary opacity-50' : '';
			$html .= '<span class="me-3' . $muted . '" title="' . self::esc($label) . '">'
				. '<i class="' . $icon . ' me-1"></i>' . (int)$count . '</span>';
		}

		return $html . '</span>';
	}

	/**
	 * Disk usage of the project: total, below it files and databases. The
	 * value comes from the daily scan; before the first run a note is shown.
	 */
	public static function storage(array $attributes): string
	{
		$stat = ProjectStats::forDomain((int)$attributes['fields']['id']);
		if ($stat === null || $stat['computed_at'] === 0) {
			return '<span class="text-body-secondary" title="' . self::esc(Lang::t('Calculated during the daily scan')) . '">' . self::esc(Lang::t('not calculated yet')) . '</span>';
		}

		return '<span class="fw-semibold">' . self::esc(ProjectStats::humanReadable($stat['total'])) . '</span>'
			. '<div class="small text-body-secondary text-nowrap">'
			. '<i class="fa-solid fa-folder me-1"></i>' . self::esc(ProjectStats::humanReadable($stat['files']))
			. ' <i class="fa-solid fa-database ms-2 me-1"></i>' . self::esc(ProjectStats::humanReadable($stat['db']))
			. '</div>';
	}

	/**
	 * Document root or redirect target. Froxlor prints cell contents with
	 * |raw, and a redirect URL is free text of the customer's domain settings.
	 */
	public static function documentroot(array $attributes): string
	{
		return '<code>' . self::esc($attributes['fields']['documentroot'] ?? '') . '</code>';
	}

	public static function ssl(array $attributes): array
	{
		return [
			'macro' => 'boolean',
			'data' => !empty($attributes['fields']['letsencrypt']),
		];
	}
}
