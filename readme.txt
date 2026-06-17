=== RH Backup ===
Contributors: robinherbeck
Tags: backup, database, export, import, migration
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Database backup with export, import and restore. Pure PHP, runs on any shared hosting, no external services.

== Description ==

RH Backup creates and restores backups of your WordPress database straight from the admin area. Export, import and restore in a clean interface that feels like a native part of WordPress.

The plugin is deliberately lean: no forced cron, no cloud connection, no external servers. The backup runs in plain PHP and therefore also works on basic shared hosting, where many backup plugins hit memory or execution limits.

= Features =

* Export the database as an archive, optionally including the uploads directory
* Restore a backup
* Manage existing backups and delete them individually
* Download a backup directly in the browser

= Security =

* Backups are stored with a random file name in a protected directory (no guessable paths)
* .htaccess protection against direct access from outside
* Every action is guarded by a capability check and a nonce

= Part of the rh-blueprint collection =

RH Backup belongs to a family of small, focused plugins by Robin Herbeck. Each module runs on its own but shares the same interface and settings system. You install only what you actually need.

== Installation ==

1. Upload the plugin via Plugins -> Add New, or install it from the directory.
2. Activate it.
3. Open RH Blueprint -> Backup.
4. Start an export, download the backup, or restore one when needed.

== Frequently Asked Questions ==

= Do I need an external service or an account? =

No. RH Backup works entirely locally on your server. No data is sent to third parties.

= Does it run on shared hosting? =

Yes, that is the main use case. The backup runs in plain PHP and does not require mysqldump or shell access.

= Are the uploads included? =

Optionally. When exporting, you can choose to include the uploads directory.

= Where are the backups stored? =

In a protected directory inside wp-content, with a random file name and .htaccess protection against direct access.

= Can I move a backup to another site? =

Yes, you can restore an exported backup on another installation. For an ongoing sync between two sites there is the sister plugin RH Sync.

== Changelog ==

= 0.2.3 =
* First release in the WordPress plugin directory.
* Export, import and restore of the database, optionally including uploads.
* Backup hardening: random file name and .htaccess protection in the backup directory.
* Clean interface in the native WordPress style.

== Upgrade Notice ==

= 0.2.3 =
First release in the WordPress plugin directory.
