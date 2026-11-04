# Project View for Froxlor 2.x

A project-centred admin view for [Froxlor](https://froxlor.org) — for agencies
and freelancers who think in customer projects, not in resource types.

**Version 1.0.0** · Froxlor 2.3.x (developed against 2.3.13, checked against
2.3.16) · PHP 8.1 or newer · GPL-2.0-or-later

Froxlor arranges resources by type: one list of all domains, one of all email
addresses, one of all databases, one of all FTP accounts. In agency work that
is the wrong axis — there you think in customer projects.

Project View adds a project-centred view: **a project is a main domain**, and
everything attached to it is shown on one page, one box each — domain,
WordPress, subdomains, email, databases, FTP, customer. The everyday tasks can
be done right in the boxes: document root and Let's Encrypt, subdomains,
mailboxes with forwarders and sender aliases, databases (with a jump to
phpMyAdmin or Adminer), FTP accounts and the file manager (WebFTP) — plus
updating WordPress or logging in to it without a password. The project
overview shows the disk space used per project (files plus databases).

Project View exists only in the admin area. The customer area gets no new
features; whoever jumps in "as customer" from the project page finds a way
back to the project in the menu.

It works with every Froxlor theme; its styles take their colours from
Bootstrap's CSS variables and follow the light and dark variants.

## Contents

- [The central point: nothing is patched](#the-central-point-nothing-is-patched)
- [Requirements](#requirements)
- [Installation](#installation)
- [Update](#update)
- [Uninstall](#uninstall)
- [Configuration](#configuration)
- [WordPress: updates, autologin, integrity check](#wordpress-updates-autologin-integrity-check)
- [Certificates for subdomains and the mail package](#certificates-for-subdomains-and-the-mail-package)
- [Backup box (optional)](#backup-box-optional)
- [Languages](#languages)
- [Security model](#security-model)
- [Design decisions](#design-decisions)
- [Tests](#tests)
- [Layout](#layout)
- [Deliberately not included](#deliberately-not-included)
- [License](#license)

## The central point: nothing is patched

The extension consists **exclusively of new files**. No Froxlor file is
modified, so a Froxlor update cannot overwrite it — Froxlor does not know
these files. The mail package does write data that belongs to Froxlor and
the configuration of Postfix and Dovecot, see
[Where Froxlor is touched](#where-froxlor-is-touched).

This is possible because Froxlor 2.x offers hook points by convention:

| What | How | Where in Froxlor |
|---|---|---|
| Menu entry | all `*.php` in `lib/navigation/` are merged, sorted | `lib/init.php` → `PhpHelper::loadConfigArrayDir` |
| Settings | all `*.php` in `actions/admin/settings/` are merged | `admin_settings.php` |
| Column definition | glob over `lib/tablelisting/` and its subdirectories | `lib/Froxlor/UI/Listing.php` |
| Cron jobs | a row in `cronjobs_run`, class loaded dynamically | `lib/Froxlor/Cli/MasterCron.php` |
| Classes | composer PSR-4: `Froxlor\` → `lib/Froxlor/` | `composer.json` |
| Templates | the Twig loader is rooted in `templates/`, paths are absolute from there | `lib/Froxlor/UI/Panel/UI.php` |
| Stylesheet | `templates/` is served by the web server, included with `<link>` | — |

The classes live in `lib/Froxlor/Ext/ProjectView/` (namespace
`Froxlor\Ext\ProjectView`): Froxlor's autoloader and its cron runner only find
classes below `lib/Froxlor/`, and the `Ext` segment keeps them clear of any
current or future upstream namespace.

## Requirements

* Froxlor 2.3.x. No version is enforced; the extension only uses the hook
  points above and Froxlor's API commands, so it is expected to keep working
  on a later 2.x release that keeps this architecture.
* PHP 8.1 or newer (the installer checks this).
* The calling admin needs quotas for the resources they create. Froxlor's
  commands check `emails`, `mysqls`, `ftps` and `subdomains` **of the admin**
  in addition to those of the customer. With `-1` (unlimited), the usual case,
  there is nothing to do.
* For the WordPress scan: outgoing access to `api.wordpress.org`. Without it
  the scan still runs, it just shows no "outdated" flag.
* For the disk usage: `du` (base system). The database size is read by the
  cron via `information_schema` with Froxlor's MySQL root access.
* The file manager button appears as soon as a WebFTP address
  (`panel.webftp_url`) is set under Settings › Panel.
* For WordPress updates and autologin additionally:
  * [WP-CLI](https://wp-cli.org/) — looked up in `/usr/local/bin/wp`,
    `/usr/bin/wp` and `/opt/wp-cli/wp`, otherwise set the path in the
    settings. Without WP-CLI the project page shows a note; everything else
    keeps working.
  * `setpriv` and `prlimit` (util-linux), `timeout` (coreutils), `bash`, `tar`, `gzip` —
    present on Debian and Ubuntu.
  * `mysqldump` or `mariadb-dump` for the database backup. It runs with the
    server's client settings (`/etc/mysql/`), i.e. with the same socket and
    TLS defaults as the `mysql` client.
  * Froxlor's cron runs as root, as usual — the jobs need that to act as the
    respective customer.

## Installation

Clone the repository **outside any web root** (for example below `/root` or
`/usr/local/src`), then run one command as root:

```bash
git clone https://github.com/lichtsignale-sk/froxlor-project-view.git
cd froxlor-project-view
./install.sh /var/www/froxlor
```

The script copies the files, clears the Twig cache and applies the
migrations. It reads the database credentials from Froxlor's own
`lib/userdata.inc.php` — nothing needs to be configured twice. It connects
via PHP/PDO with the same options as Froxlor itself (socket or host and port,
TLS); a MySQL command line client is not needed.

Only look at what would happen:

```bash
./install.sh /var/www/froxlor --dry-run
```

Files only, migrations later:

```bash
./install.sh /var/www/froxlor --skip-migrations
php migrations/apply.php /var/www/froxlor
```

Afterwards the admin area has a new menu item **Projects**, and the settings
have a new group **Project View**.

## Update

The same again — the migrations are idempotent:

```bash
git pull && ./install.sh /var/www/froxlor
```

**Run `install.sh` again after every Froxlor update.** An update cannot
overwrite the files because Froxlor does not know them, but an update that
replaces the whole directory removes anything unknown. Running the installer
again is cheap and makes that irrelevant.

## Uninstall

```bash
./uninstall.sh /var/www/froxlor           # files gone, assignments kept
./uninstall.sh /var/www/froxlor --purge   # additionally drop the extension's tables and settings
```

In both cases the cron jobs are unregistered, the autologin plugins are
removed from the customers' WordPress installations and the mail SNI is taken
down (Postfix and Dovecot serve the panel certificate for all names again).
Without `--purge` the assignments are kept — whoever uninstalls only
temporarily does not lose any work. The WordPress backups below
`/var/backups/froxlor-project-view/` stay; nobody deletes them automatically.
The subdomains created by the mail package stay too — they belong to the
customer.

## Configuration

Settings › **Project View**:

| Setting | Default | Meaning |
|---|---|---|
| Adminer URL | empty | Address of an Adminer installation. Empty: no Adminer button. User and database are prefilled, the password is not. phpMyAdmin and Roundcube are taken from Froxlor's own settings. |
| Path to WP-CLI | empty | Empty: search the usual locations. |
| Directory for WordPress backups | `/var/backups/froxlor-project-view/` | Must be outside the customer directories; root-only. |
| Backups kept per installation | 3 | Older ones are deleted after each new backup. |
| Subdomains automatically with Let's Encrypt | on | See below. |
| Mail package for email domains | Dry run | Off / Dry run / On, see below. |
| Roundcube directory for webmail. | `/var/lib/roundcube/public_html/` | Public directory of a shared Roundcube installation. |
| PHP-FPM socket for Roundcube | empty | Socket of the PHP-FPM pool Roundcube runs in. Without it the mail package only creates mail. |

All settings are stored in `panel_settings` with the group `ext_projectview`.

## WordPress: updates, autologin, integrity check

The project page executes nothing itself. A click creates a **job**; Froxlor's
cron processes it within a minute, and the log then appears in the WordPress
box.

| Job | What happens |
|---|---|
| Check | Version, available core, plugin and theme updates, administrators, site address, integrity check. Also runs once a day after the scan. |
| Back up | Database and files (without uploads and caches) to `<backup dir>/<domain id>/<installation id>-<time>/` (`database.sql.gz`, `files.tar.gz`) |
| Update everything / core / plugins / themes | **back up first** — if the backup fails, nothing is updated —, then `wp core update` + `update-db`, `plugin update --all`, `theme update --all`, then check |
| Set up / remove autologin | adds or removes the one-time login plugin |

At most one job runs per installation at a time; a double click does not
create anything twice. The last three backups per installation are kept
(configurable).

**Restoring** is deliberately manual, because it should be rare and never
happen without looking at the situation. As the customer:

```bash
cd /var/customers/webs/<customer>/<website>
tar -xzf /var/backups/froxlor-project-view/<…>/files.tar.gz
gunzip -c /var/backups/froxlor-project-view/<…>/database.sql.gz | wp db import -
```

**Autologin.** Each installation has an Ed25519 key pair. The secret key stays
in the Froxlor database; only a small must-use plugin with the public key goes
into the installation (`wp-content/mu-plugins/froxlor-project-view-login.php`).
"Log in as …" creates a signed link that is valid for 60 seconds and exactly
once, and only logs in users with the `manage_options` capability. A
signature instead of a shared secret, because the plugin lies readable in the
web directory: a public key there gives nothing away. The link is only issued
for a site address that belongs to the same customer. Every login is written
to Froxlor's system log.

### Integrity check

Froxlor itself does not check for malicious code. Project View adds a check
that knows WordPress, as part of the **Check** job:

* `wp core verify-checksums` and `wp plugin verify-checksums --all` compare
  every file of the core and the plugins with the checksums from
  wordpress.org. Modified, added and missing files are reported. For the core
  `wp-content` is left out, and WP-CLI reports added files only in `wp-admin`
  and `wp-includes`, not in the root directory; for plugins `readme.txt` and
  `readme.md` are not compared (WP-CLI defaults in both cases).
* A backdoor in `wp-content/uploads` is not found by verify-checksums, because
  uploads belong to no package. That directory is therefore additionally
  searched for files with the extensions `.php`, `.phtml` and `.phar`, hidden
  directories included.

With a clean result the WordPress box shows a single unobtrusive line. With a
finding, a warning box shows the counts and, expandable, the affected paths
(at most 50; the counts are always complete); the WordPress tile at the top
turns yellow. The job log lists the first 20 paths. A finding does not make
the job fail.

Plugins for which wordpress.org has no checksums — premium plugins, custom
code — are listed as **not verifiable**, not as a finding. The same applies
when wordpress.org is unreachable or WordPress no longer loads: the result
says what was not checked, and why.

A finding does not necessarily mean an attack: a hand-patched plugin or an
`index.php` that a plugin puts into its upload folder are reported just the
same. Whoever wants to look opens the file as the customer — Project View does
not read it.

The uploads search runs as root but is strictly read-only like the scan: it
only lists directories and reads file attributes, opens no file, includes
nothing and follows no symbolic link. Directory depth (16) and the number of
entries (200,000) are limited; if a limit is reached, the result says so.

## Certificates for subdomains and the mail package

**Subdomains with their own certificate.** Froxlor creates subdomains without
Let's Encrypt and then serves the main domain's certificate — which never
matches the name; browsers and strict clients (such as an updater with
`sslverify`) reject it. A cron job running every minute therefore turns on
Let's Encrypt for every subdomain whose main domain has it. The HTTPS
redirect follows the main domain. Beforehand it checks itself whether the DNS
record (and with a www alias also `www.<subdomain>`) points to this server;
otherwise it looks again hourly and gives up after seven days. Every
subdomain is switched **only once**: whoever turns Let's Encrypt off
afterwards keeps it that way. On the first run this also applies to all
existing subdomains. Can be turned off under Settings › Project View.

**Mail package.** For every email domain (not "email only") the cron creates
four subdomains with Let's Encrypt:

| Subdomain | Purpose |
|---|---|
| `mail.<domain>` | server for IMAP 993, POP3 995, SMTP 465/587 with a valid certificate; in the browser a redirect to webmail |
| `webmail.<domain>` | Roundcube from the server's shared installation |
| `autoconfig.<domain>` | Thunderbird and others find server and ports by themselves |
| `autodiscover.<domain>` | the same for Outlook |

A DNS record `*` of the customer domain pointing to this server is required
(or the four names individually). Without matching DNS the cron waits and
shows that in the email box. The mode starts as **Dry run**: Project View only
shows what would be created. Only "On" creates. Names that already exist or
belong to the panel (host name, `system.froxloraliases`) are left alone. If a
customer deletes one of these subdomains, the cron does not create it again.
The four subdomains count towards the customer's subdomain quota; if it is
not enough, the cron rather creates nothing than half a package. "Off"
creates nothing new but maintains what already exists.

webmail. and the autoconfiguration need the socket of the PHP-FPM pool in
which Roundcube runs and the Roundcube directory in the settings. These three
subdomains deliberately do not run with the customer's PHP.

### Where Froxlor is touched

Here the extension departs from "only new files", in exactly three places:

- For webmail., autoconfig. and autodiscover. the cron writes the special
  settings (`panel_domains.specialsettings`) and turns off the customer's PHP —
  Froxlor's API accepts special settings only for main domains. The lines
  start with `# Froxlor Project View mail package`. Froxlor resets them
  whenever the main domain is edited; after changes in Project View they come
  back immediately, after changes in Froxlor's own form on the next cron run
  (until then, one to five minutes, webmail. is unreachable).
- Postfix gets `tls_server_sni_maps = hash:/etc/postfix/froxlor-project-view-sni`,
  Dovecot `conf.d/98-froxlor-project-view-sni.conf` with one `local_name`
  block per mail. subdomain (Dovecot 2.3 syntax, or 2.4 syntax with
  `ssl_server_cert_file`/`ssl_server_key_file` when Froxlor is configured for
  `dovecot24`). The keys are in `/etc/ssl/froxlor-project-view-mail/`
  (root only). The files are generated from the certificates in Froxlor's
  database, also after every renewal; before reloading, `doveconf` checks the
  file, and on an error the old one is restored. Froxlor's own entries
  (`smtpd_tls_cert_file`, `99-froxlor.ssl.conf`) stay as they are and keep
  applying to all other names. If `tls_server_sni_maps` is already set to
  something else, nothing is overwritten. Only services listed in Froxlor
  under `system.le_renew_services` are touched; if a service drops out
  there, the cron removes its file. If loading fails, the cron retries the
  same state only after an hour — immediately if you delete
  `/etc/ssl/froxlor-project-view-mail/state.json`.
- `migrations/remove.php` takes the SNI down again. The created subdomains
  stay, they belong to the customer.

**Froxlor's own mail SNI.** A future Froxlor release may ship per-domain mail
SNI itself (setting `mail.sni_enabled`, upstream pull request
froxlor/froxlor#1423). As soon as that setting exists and is on, Project View
stands down: it writes no SNI files, removes its own ones cleanly and says so
next to the mail package setting. The subdomains are still created.

## Backup box (optional)

When the separate **Backup** extension (namespace `Froxlor\Ext\Backup`) is
installed, the project page shows a Backup box with the time of the
customer's last backup and links to restore the project (all, website,
email or databases). Project View only checks
`class_exists(\Froxlor\Ext\Backup\ProjectViewIntegration::class)` and calls
its `status()` and `restoreParams()`; without the Backup extension the box is
simply absent. The box is shown only to admins with the right to change
server settings. Neither extension requires the other.

In the other direction, Project View offers the Backup extension an optional
hook, `Froxlor\Ext\ProjectView\BackupIntegration::resourceAssignments(int
$customerId)`. It returns the customer's database and FTP assignments
(`['domainid' => int, 'type' => 'database'|'ftp', 'id' => int]`), only for
resources and projects that really belong to that customer, so a project
restore can include them. Backup checks for it with `class_exists()`.

## Languages

All texts — pages, settings, menu, messages and job logs — exist in English
and German. The language of the logged-in user applies (Froxlor profile,
otherwise the panel's default language): German ("Deutsch", `de`) yields
German, any other language English. Without a session (cron jobs) the panel's
default language applies; job logs and mail package messages are stored in
that language when they are written.

Froxlor's own language files (`lng/*.lng.php`) stay untouched. The extension
brings its own small layer, `lib/Froxlor/Ext/ProjectView/Lang.php`: the
English source text is the key (`Lang::t('Text')` in PHP,
`{{ pv_t('Text') }}` in Twig), the German versions are in
`lib/Froxlor/Ext/ProjectView/lang/de.php`. If a translation is missing, the
English text appears. `tests/lang.php` reports every text without a German
entry and placeholders that do not match. The Twig function is called
`pv_t` so that it never collides with another extension's function.

Froxlor's system log entries are always English.

## Security model

**Panel (web) side**

* **Writes go through Froxlor's API commands** (`Domains`, `SubDomains`,
  `Emails`, `EmailAccounts`, `EmailForwarders`, `EmailSender`, `Mysqls`,
  `Ftps`) with the logged-in admin's user info, so validation, quotas, system
  tasks and logging apply unchanged. Own SQL exists only on the extension's
  own `ext_projectview_*` tables, with prepared statements.
* **Visibility** follows Froxlor's rule: an admin only sees projects of their
  own customers unless they have `customers_see_all`. Every resource ID from a
  form is looked up in the current project's lists before any command runs.
* **Resources stay with their customer.** A database or FTP account can only
  be assigned to a project of the customer it belongs to, and a resource that
  is already assigned can only be moved away from a project the admin can
  see. The project page and the backup hook show only resources whose owner
  matches the project's customer, whatever the mapping table says.
* **CSRF** is handled by Froxlor's form token (`csrf_token`) on every form.
* **Deletes need the typed name** of the subdomain, address or database —
  checked in the browser (`pattern`) and again on the server against the name
  from the database.
* **Output escaping.** Froxlor prints error messages, sidebar labels and table
  cells with `|raw`, and Vue compiles the whole page. Every error message of
  the extension is HTML-escaped and wrapped in `v-pre` (messages can contain
  customer data such as names from API errors); sidebar labels and table cells
  are escaped by the extension; all page content is in `v-pre`, so `{{ … }}` in
  a customer's description is shown literally. Template errors are logged,
  not shown.
* **Autologin** uses Ed25519 signatures, 60-second single-use links and logs
  in `manage_options` users only. The site address reported by WordPress comes
  from the customer's database and is accepted only in one strict form
  (`http(s)://host[:port][/path]`, IDN converted to punycode, no user info,
  query, fragment, backslashes or control characters); the redirect and the
  "Open wp-admin" link use a URL rebuilt from the validated parts. The host
  must belong to the same customer and must not be the panel's host name, one
  of its aliases (`system.froxloraliases`) or below them — such a host would
  receive the panel's session cookie. Error messages do not repeat the address.

**Root (cron) side**

* **No customer code runs as root.** WP-CLI loads WordPress and thus all of
  the customer's plugins — so it runs via `setpriv` with the customer's user
  and group ID, without supplementary groups and with `--no-new-privs`, with
  a scrubbed environment (`env -i` plus an explicit allowlist, the
  customer's own home directory), with all inherited file descriptors closed,
  with a time limit (120 seconds for read-only checks), with `prlimit` caps on
  memory (4 GiB), CPU time and file size, and without loading plugins and
  themes. Note that the customer's own code still runs outside the PHP-FPM
  pool's `open_basedir` and `disable_functions`; the number of processes is
  not capped, since the limit counts all of the customer's processes. stdout and stderr are read through size-capped pipes.
* **Root never enters a customer directory.** The process starts in `/`; only
  after the privilege drop does the customer process change into the
  installation (`cd -P`) and verify that its physical path is exactly the one
  checked before. If a path component was swapped for a symbolic link in the
  meantime — for instance into another customer's installation — the call is
  aborted. Every write into customer directories (the autologin plugin) and
  every read for a backup runs as the customer.
* **The scans are strictly read-only.** The WordPress scan, the disk usage and
  the uploads search run as root over customer directories, but never include
  a customer file (the version is read from `wp-includes/version.php` with a
  regular expression, from a regular file only, with a bounded non-blocking
  read), check every path component for symbolic links, never follow them,
  and are limited in depth and number of entries. At most 20 installations
  per project are recorded. A remaining limit: a customer who swaps a
  directory for a link exactly between the check and the read can at most
  make the scan read a version number or list file names elsewhere — it
  never opens, executes or writes anything there.
* **Backups** are produced by the customer (tar and the dump run as the
  customer) and written by root only into the backup directory, which must be
  outside the customer directories, owned by root, not group/world-writable
  and free of symbolic links (mode 0700 — backups contain `wp-config.php` with
  the database password). Before a backup the free space is checked, and the
  size of what root writes is capped to the free space minus a reserve.
* **Scheduling.** Jobs queued by an admin run before the daily background
  checks, and jobs are distributed fairly between customers, so one customer
  cannot hold up the queue.
* **Known limit of the time limit.** `timeout` and the hard deadline kill the
  process group of a call. A customer process that detaches into a new
  session (`setsid`) escapes both and keeps running — with the customer's own
  rights only, like any process the customer could start from their website;
  the cron neither waits for it nor reads from it.
* **Other root-owned places** refuse symbolic links: the per-customer WP-CLI
  home directories below `/var/lib/froxlor-project-view/wpcli`, and the mail
  SNI files (written to a temporary file created exclusively and renamed into
  place; keys mode 0600).

**Installation**

* **Installer and uninstaller**, run as root, refuse to run from a directory
  another user could modify, and write and delete inside the Froxlor
  directory as the owner of that directory: in a new session without a
  controlling terminal (no keystroke injection into root's terminal), with a
  reset environment, without root's file descriptors and with the output
  stripped of control characters (`scripts/privileges.sh`). If the Froxlor
  directory belongs to root, root writes itself only if every directory from
  `/` down is root-owned and not writable by others. Paths containing
  symbolic links are refused before anything is written; files are unpacked
  with `umask 022` and without taking over owners or modes.
* **The migration helpers never execute `lib/userdata.inc.php`.** The file
  belongs to the panel user while the helpers usually run as root; it is
  parsed as data instead (only literal assignments are accepted), and a
  symbolic link there is refused. The helpers load only code from this
  repository. Every PHP file that is not meant to be requested over HTTP
  refuses to run outside the CLI; the installed directories carry empty
  `index.html` files like Froxlor's own. Clone the repository outside any web
  root.

## Design decisions

**Writing goes through Froxlor's own commands without exception.**
`Domains::getLocal($userinfo, $params)->add()` and friends encapsulate
validation, quota checks, system task creation and logging. Interestingly, the
commands have long supported the admin case through a `customerid`
parameter — Froxlor only lacks the user interface for it. The project page
fills exactly that gap.

**Deleting only with a typed name.** Deleting in Froxlor triggers system tasks
that remove data irrevocably. Every delete therefore sits in a separate danger
zone at the end of its entry and requires typing the name of the subdomain,
address or database. Domain base configuration and PHP settings stay in
Froxlor's own forms, linked from the page.

**Databases and FTP need an assignment of their own.** `panel_databases` and
`ftp_users` carry only a `customerid`, no `domainid` — Froxlor cannot attach
them to a website at all. That is what `ext_projectview_resources` is for.
FTP is suggested via the path (`homedir` below the `documentroot`), databases
via name similarity. **Suggestions are never written automatically** — a
wrongly guessed assignment goes unnoticed once it is in the database. What is
created on the project page, on the other hand, is assigned immediately.

**The "Unassigned" section never goes away.** There will always be resources
without a project: test databases, shared accounts, leftovers. A system that
pretends they do not exist loses trust exactly there.

**Disk usage is computed by the cron, not per page load.** `du` over customer
directories needs root and can take a while; that does not belong in an
overview that reloads on every visit. The daily scan stores the value, the
pages only read it. `du` follows no symbolic links — a customer cannot make it
count foreign directories.

**No JavaScript, and Vue stays out.** Froxlor mounts Vue on `<body>`: Vue would
evaluate every `{{ … }}` in the page content as an expression and remove
`<style>` and `<script>` blocks. The content areas of Project View are
therefore in `v-pre`, the styles come as a separate file
(`templates/ProjectView/style.css`), and the delete confirmation is checked
by the browser via the `pattern` attribute.

**Own data in own tables.** All data of the extension lives in its own
`ext_projectview_*` tables and `panel_settings` group, so a later migration
remains a data export instead of an excavation. Froxlor's `main` branch is a
complete rewrite (Laravel) that this extension does not target.

## Tests

These run without any setup:

```bash
php tests/lang.php                   # every text has a German entry, placeholders match
php tests/connection.php             # userdata.inc.php parsing, DSN and TLS like Froxlor
php tests/wordpress-scanner.php      # detection, search depth, symlinks, version reading
php tests/wordpress-integrity.php    # verify-checksums output, PHP in uploads, symlinks
php tests/resource-suggestions.php   # path and name suggestions, including the prefix trap
php tests/wordpress-cli.php          # privilege drop, cd as the customer, scrubbed environment, output caps
php tests/wordpress-login.php        # strict site address parsing, rebuilt URLs, allowed hosts
php tests/domain-tls.php             # subdomain LE, mail package, SNI files, autoconfiguration
php tests/web-output.php             # error messages and table cells are escaped (XSS regression)
php tests/ownership.php              # resources only within their customer, backup hook, autologin hosts (SQLite)
```

With a Froxlor directory (only its `vendor/` is used, for Twig):

```bash
php tests/templates.php /path/to/froxlor   # parse and render all templates in English and German
```

`tests/database.php` checks the real queries against Froxlor's original schema
in a disposable database — whether column names, tenant separation and key
figures are really right. How to set up such a database (for example in a
throwaway MariaDB container) is described at the top of the file; it needs
nothing but Froxlor's `install/froxlor.sql.php`, `migrations/001_init.sql`
and `tests/seed.sql`:

```bash
php tests/database.php /path/to/froxlor
```

It checks among other things that a restricted admin only sees their own
projects, that the standard subdomain does not show up as a project, that the
counts of several detail tables do not multiply each other, that with several
WordPress installations the oldest version determines the risk, and that an
assignment cannot be removed from within a foreign project.

The write actions and WordPress jobs are best tested end to end in a
disposable Froxlor installation (for example Froxlor in a container with a
test customer and a real WordPress): updates with backup, autologin including
reused, manipulated and expired links, a customer who points the plugin
directory to `/etc`, and the integrity check with a modified core file, an
`uploads/x.php` and a plugin without checksums.

`tests/` and `migrations/` are not copied into Froxlor by the installer.

## Layout

```
install.sh                                   installation and update
scripts/privileges.sh                        dropping root privileges safely (used by both scripts)
uninstall.sh                                 removal
admin_projects.php                           overview, project page, write actions
admin_projects_return.php                    way back from the customer area to the project
actions/admin/settings/190.ext.projectview.php   settings
lib/navigation/50.ext.projectview.php        menu entries
lib/tablelisting/admin/tablelisting.ext.projectview.php   overview columns
lib/Froxlor/Ext/ProjectView/
  ProjectRepository.php                      reads projects and resources
  ProjectStats.php                           disk usage per project (files + DB)
  ResourceMapper.php                         DB/FTP assignment, suggestions
  WordpressScanner.php                       file system scan, version comparison
  WordpressJobs.php                          queue of WordPress jobs
  WordpressMaintenance.php                   check, back up, update, autologin
  WordpressIntegrity.php                     verify-checksums results, PHP in uploads
  WordpressCli.php                           run WP-CLI as the customer
  WordpressLogin.php                         signed one-time links, must-use plugin
  SubdomainSsl.php                           Let's Encrypt for subdomains
  MailPackage.php                            create mail./webmail./autoconfig./autodiscover.
  MailPackage/autoconfig.php                 autoconfiguration endpoint (Thunderbird, Outlook)
  MailPackage/Autoconfiguration.php          its XML
  MailSni.php                                certificates of the mail. subdomains for Postfix/Dovecot
  AdminContext.php                           user context for API calls from the cron
  BackupIntegration.php                      optional hook for the Backup extension
  Html.php                                   escaping for messages Froxlor prints raw
  Callbacks/Project.php                      column callbacks
  Cron/WordpressScanCron.php                 daily scan (WordPress + disk usage)
  Cron/WordpressJobCron.php                  runs jobs, every minute
  Cron/DomainTlsCron.php                     subdomain LE, mail package, SNI, every minute
  Lang.php, lang/de.php                      English/German layer and German catalog
templates/ProjectView/                       Twig templates, style.css
migrations/
  001_init.sql … 007_job_priority.php        tables, settings, cron registration
  apply.php                                  applies the migrations
  remove.php                                 cron jobs, autologin plugins and mail SNI away, optionally data
  connection.php                             database connection from userdata.inc.php
tests/                                       test scripts, see above
```

### Database objects

| Object | Purpose |
|---|---|
| `ext_projectview_resources` | assignment of databases and FTP accounts to projects |
| `ext_projectview_wp_installs` | detected WordPress installations, last check, autologin key, integrity result |
| `ext_projectview_wp_latest` | cached latest WordPress version |
| `ext_projectview_wp_jobs` | WordPress job queue and logs |
| `ext_projectview_stats` | disk usage per project |
| `ext_projectview_ssl_subdomains` | which subdomains got Let's Encrypt automatically |
| `ext_projectview_mail_package` | state of the mail package per domain and kind |
| `panel_settings` group `ext_projectview` | settings |
| `cronjobs_run` module `ext/projectview` | the three cron jobs |

## Deliberately not included

* No change to existing Froxlor lists, no copy of Froxlor templates. The old
  views remain fully functional as a fallback.
* No automatic restore of a backup, no plugin management beyond updates
  (installing, activating, single updates).
* No new features in the customer area.

## License

Copyright (C) 2026 Samuel Kümmel

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).
