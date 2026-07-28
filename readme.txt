=== RH Backup ===
Contributors: robinherbeck
Tags: backup, database, export, import, migration
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Database backup with export, import and restore, plus optional offsite copies to your own Google Drive. Pure PHP, runs on any shared hosting.

== Description ==

RH Backup creates and restores backups of your WordPress database straight from the admin area. Export, import and restore in a clean interface that feels like a native part of WordPress.

The plugin is deliberately lean and runs in plain PHP, so it also works on basic shared hosting where many backup plugins hit memory or execution limits. Backups stay on your own server unless you connect Google Drive yourself, and even then the data goes straight from your site to your Drive, with no service in between.

= Features =

* Export the database as an archive, optionally including the uploads directory
* Restore a backup
* Manage existing backups and delete them individually
* Download a backup directly in the browser
* Offsite backup to Google Drive on a schedule, into the customer's own account

= Offsite backup to Google Drive =

A full copy of the site lands in the customer's own Google Drive, weekly, monthly or quarterly. The point is independence: if the agency or its server disappears, the customer still holds a complete copy in an account only they control.

Setup takes one code. Install the plugin, open the backup tab, click connect. The site shows a short code, you enter it at google.com/device and confirm in your own Google account. Nothing else to configure, no API keys to obtain, no redirect URI to register and no callback server in between, so nothing in this chain can break later.

The plugin requests only the drive.file scope. That means it can see exclusively the files it created itself and never the rest of the Drive, which is also why it needs no Google verification.

Uploads run in chunks and can resume, so backups of several gigabytes survive PHP timeouts. Old copies are removed only after the new one has been verified byte for byte in Drive, and every failed run sends an email.

= Security =

* Backups are stored with a random file name in a protected directory (no guessable paths)
* .htaccess protection against direct access from outside, plus a hint for Nginx setups, where .htaccess has no effect
* Every action is guarded by a capability check and a nonce
* Backup files are written with restrictive permissions (0600)
* A safety copy is taken before every restore, and the database is rolled back if the restore fails
* Only export and restore may run at a time, guarded by a lock
* Archives from outside are treated as untrusted: statement allowlist, no executable files in uploads, and a limit against zip bombs

= Data protection =

Backups contain personal data: user accounts including password hashes, session tokens, comments and everything stored in wp_options. Treat them accordingly:

* They stay on your own server, nothing is transmitted to third parties
* They are stored in wp-content/rh-blueprint-data/backups/, safety copies in .../auto-backups/
* By default the five most recent backups and three most recent safety copies are kept, older ones are deleted automatically. Adjust this via the filters rh-backup/keep_backups and rh-backup/keep_safety_copies, or set them to 0 to disable rotation
* If you download a backup, the same applies to the copy on your own machine
* With offsite backup enabled, the same data also lands in the Google account you connected. As the site operator you are responsible for that processing, so cover Google as a processor in your records and your privacy policy
* As the site operator, list this processing in your records and delete backups you no longer need

= Part of the rh-blueprint collection =

RH Backup belongs to a family of small, focused plugins by Robin Herbeck. Each module runs on its own but shares the same interface and settings system. You install only what you actually need.

== Installation ==

1. Upload the plugin via Plugins -> Add New, or install it from the directory.
2. Activate it.
3. Open RH Blueprint -> Backup.
4. Start an export, download the backup, or restore one when needed.

== Frequently Asked Questions ==

= Do I need an external service or an account? =

No. RH Backup works entirely locally on your server by default, and nothing is sent anywhere.

The one exception is the optional offsite backup: if you connect Google Drive, the backups are uploaded to your own Google account. That connection is yours, you grant it yourself and can revoke it at any time in your Google account. The plugin only ever sees the files it created there.

= Does it run on shared hosting? =

Yes, that is the main use case. The backup runs in plain PHP and does not require mysqldump or shell access.

= Are the uploads included? =

Optionally. When exporting, you can choose to include the uploads directory.

= Where are the backups stored? =

In a protected directory inside wp-content, with a random file name and .htaccess protection against direct access.

= Can I move a backup to another site? =

Yes, you can restore an exported backup on another installation. For an ongoing sync between two sites there is the sister plugin RH Sync.

== Changelog ==

= 0.4.0 =
* New: offsite backup to Google Drive. A full copy of the site is uploaded to the customer's own Google account on a schedule, so a copy survives even if the agency or its server does not.
* Connecting uses Google's device flow: the site shows a short code, you confirm it in your Google account. No redirect URI, no callback server, no third party in the loop.
* Only the drive.file scope is requested. The plugin sees exclusively the files it created itself, never the rest of the Drive.
* Uploads are resumable and chunked, so multi-gigabyte backups survive PHP timeouts and memory limits. An interrupted upload continues where it stopped instead of starting over.
* Old copies are only deleted after the new one has been verified byte for byte in Drive.
* Every failed run sends an email, because a backup that fails silently is the worst kind.
* The Google refresh token is encrypted at rest with libsodium, keyed from the WordPress salts in wp-config.php. Nothing about the connection is ever printed to a page or a log.
* Full site backup: database, uploads, themes, plugins, mu-plugins and the WordPress core, written straight to Drive without ever landing on disk as a file. Measured on a 93 MB site: 2 MB of disk in use at any moment. Restoring a full site archive is done by hand over FTP or SSH, not through the plugin.
* Backups are stored in one place, not two. A switch at the top of the page chooses between this server and Google Drive, and everything follows it: what a manual run produces, what the schedule produces, and what the restore list offers. Switching offers to move what is already there, in either direction.
* An external endpoint can trigger the schedule, for sites where WP-Cron never runs because nobody visits them. The plugin also checks its own schedule and warns, then mails, when runs stop happening.
* Only one driver works on a run at a time. The loopback, the cron watchdog and the external endpoint could previously collide, which killed the run with a misleading "check your disk space". The external endpoint now leaves a healthy run alone and only steps in when one has genuinely stalled.
* Old copies in Drive go to the trash instead of being deleted outright, so there are thirty days to notice a mistake. Rotation runs unattended, and it deletes.
* Existing backups from before this version are never rotated away. They were created at a time when nothing was ever deleted, and an update must not take that back. New backups live in per-purpose folders and are rotated there.
* The chunk size for uploads is derived from the server's memory limit instead of being fixed at 8 MB, which was too much on a 64 MB host.

= 0.3.0 =
* Restore now takes a safety copy first and rolls the database back if the restore fails. Previously an abort left a half-replaced database with no way back.
* Fixed silent data loss: cells larger than 64 KB (serialised builder data, large options, base64 in post content) were torn apart while restoring, the affected rows went missing without any error.
* Failed database statements now abort the restore instead of being ignored, and failed writes abort the export. A backup that could not be written completely is no longer reported as successful.
* Export verifies the finished archive instead of only checking that a file exists.
* Media files that cannot be written are reported instead of being skipped silently.
* Works on hosts where set_time_limit is disabled. Export and restore aborted there immediately before.
* Archives from outside are now validated: statement allowlist, no executable files in uploads, protection against zip bombs and a disk space check.
* Object cache and permalinks are flushed after a restore.
* Automatic rotation of backups and cleanup of stale job folders, no longer dependent on RH Sync being installed.
* Export and restore are guarded by a lock, and you stay logged in after a restore whenever your account still exists.
* Backup files are written with restrictive permissions, failures are written to the error log.

= 0.2.7 =
* Bundles db-engine 1.1.3: fixes a restore that aborted with "no db_prefix" when the media library contained a file named manifest.json. The unpacker now matches db.sql and manifest.json by full path instead of filename.

= 0.2.3 =
* First release in the WordPress plugin directory.
* Export, import and restore of the database, optionally including uploads.
* Backup hardening: random file name and .htaccess protection in the backup directory.
* Clean interface in the native WordPress style.

== Upgrade Notice ==

= 0.2.3 =
First release in the WordPress plugin directory.
