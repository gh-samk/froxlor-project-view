-- Setting for the Adminer address.
--
-- Froxlor's Settings::Get returns null for unknown keys; the row is created
-- anyway, so that saving via the settings page finds an existing row.
-- Idempotent.
INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`)
SELECT 'ext_projectview', 'adminer_url', '' FROM DUAL
WHERE NOT EXISTS (
	SELECT 1 FROM `panel_settings` WHERE `settinggroup` = 'ext_projectview' AND `varname` = 'adminer_url'
);
