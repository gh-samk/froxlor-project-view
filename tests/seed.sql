-- Test data for tests/database.php
--
-- Deliberately covers the edge cases where the project logic could fail: two
-- admins with separate customers, a standard subdomain (technically a main
-- domain, but not a project), several detail tables filled at the same time
-- (so counts must not multiply), a forwarder with and without the own
-- address as destination, and assigned and unassigned resources side by side.
--
-- ONLY load into a disposable database — the DELETEs empty tables.

SET SESSION sql_mode='';

DELETE FROM panel_domains;
DELETE FROM panel_customers;
DELETE FROM mail_virtual;
DELETE FROM panel_databases;
DELETE FROM ftp_users;
DELETE FROM ext_projectview_resources;
DELETE FROM ext_projectview_wp_installs;

INSERT INTO panel_customers (customerid, adminid, loginname, name, firstname, company, email, standardsubdomain)
VALUES (1, 1, 'customer1', 'Miller', 'Anna', 'Example Ltd', 'a@customer1.example', 3),
       (2, 2, 'customer2', 'Smith',  'Ben',  '',            'b@customer2.example', 0);

-- Domain 3 is the standard subdomain of customer 1 and must not count as a project.
INSERT INTO panel_domains (id, domain, domain_ace, customerid, adminid, parentdomainid, documentroot, letsencrypt)
VALUES (1, 'customer1.example',          'customer1.example',          1, 1, 0, '/var/customers/webs/customer1/',      1),
       (2, 'shop.customer1.example',     'shop.customer1.example',     1, 1, 1, '/var/customers/webs/customer1/shop/', 0),
       (3, 'customer1.server.example',   'customer1.server.example',   1, 1, 0, '/var/customers/webs/customer1/',      0),
       (4, 'customer2.example',          'customer2.example',          2, 2, 0, '/var/customers/webs/customer2/',      0);

-- info@ has a real forwarder, books@ only itself as destination, mail@ nothing.
INSERT INTO mail_virtual (id, domainid, customerid, email, email_full, destination)
VALUES (1, 1, 1, 'info@customer1.example',  'info@customer1.example',  'info@customer1.example boss@customer1.example'),
       (2, 1, 1, 'books@customer1.example', 'books@customer1.example', 'books@customer1.example'),
       (3, 1, 1, 'mail@customer1.example',  'mail@customer1.example',  ''),
       (4, 4, 2, 'info@customer2.example',  'info@customer2.example',  '');

INSERT INTO panel_databases (id, customerid, databasename, description)
VALUES (1, 1, 'customer1_wp',   'WordPress'),
       (2, 1, 'customer1_test', ''),
       (3, 2, 'customer2_wp',   '');

INSERT INTO ftp_users (id, customerid, username, homedir, description, password, login_enabled)
VALUES (1, 1, 'customer1_ftp1', '/var/customers/webs/customer1/',      '', 'x', 'Y'),
       (2, 1, 'customer1_shop', '/var/customers/webs/customer1/shop/', '', 'x', 'Y'),
       (3, 2, 'customer2_ftp1', '/var/customers/webs/customer2/',      '', 'x', 'Y');

-- Project 1: two databases and one FTP account assigned; the rest stays unassigned.
INSERT INTO ext_projectview_resources (domainid, resource_type, resource_id, assigned_at)
VALUES (1, 'database', 1, 0),
       (1, 'database', 2, 0),
       (1, 'ftp',      1, 0);

-- Two installations on one project: the older one determines the risk.
INSERT INTO ext_projectview_wp_installs (domainid, path, version, last_seen)
VALUES (1, '/var/customers/webs/customer1',      '6.4.2', 0),
       (1, '/var/customers/webs/customer1/blog', '5.9',   0);

UPDATE ext_projectview_wp_latest SET version = '6.9' WHERE id = 1;
