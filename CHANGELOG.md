# Changelog

## 1.0.1

- Security: WP-CLI runs under `prlimit` (memory 4 GiB, CPU time, file size, no core dumps); `prlimit` (util-linux) is now required.
- Security: the WordPress autologin refuses websites reached over plain `http://`, so the one-time link and the login cookie are never sent unencrypted.

## 1.0.0

Initial public release.

- Project page per main domain with boxes for domain/SSL, WordPress,
  subdomains, email (mailboxes, forwarders, sender aliases), databases, FTP
  and customer; typed-name confirmation for every delete.
- Project overview with disk usage, WordPress version and resource counts;
  "Unassigned" page for databases and FTP accounts with suggestions.
- WordPress jobs run by the cron as the customer: check, back up, update
  (with a backup first), one-time autologin via Ed25519-signed links, and an
  integrity check (verify-checksums plus PHP files in uploads).
- Let's Encrypt for subdomains and the optional mail package
  (mail./webmail./autoconfig./autodiscover.) with per-domain SNI for Postfix
  and Dovecot 2.3/2.4; stands down when Froxlor's own mail SNI is enabled.
- English and German user interface.
- Optional Backup box when the separate Backup extension is installed.
