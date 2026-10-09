# Full Journal Transfer (OJS 3.5)

Command line plugin that exports a whole journal of an OJS 3.5 installation into a
single `tar.gz` archive and imports it into another OJS 3.5 installation as a new
journal: settings, users and roles, issues, articles with their complete editorial
workflow (review rounds, reviewer files and forms, decisions, discussions), the
activity log (event log and e-mail log), usage statistics (metrics) and the files.

This is the OJS 3.5 rewrite of the [Lepidus fullJournalTransfer plugin](https://github.com/lepidus/fullJournalTransfer)
maintained by [academic-journals-cz](https://github.com/academic-journals-cz).
A Czech version of this document is in [`docs/README-cs.md`](docs/README-cs.md).

## Compatibility

* OJS **3.5.0** (developed and tested with 3.5.0-5). The export and the import must run
  on the **same OJS version** (the archive contains raw database rows of several tables).
* PHP 8.2+, `php-xml`, `php-mbstring`, `php-intl` (the requirements of OJS 3.5).
* The archive is written and read in pure PHP (`zlib` extension); no external `tar`
  binary is needed, so the plugin works on Windows installations as well.
* All plugins the journal uses (theme, DOI registration agency, etc.) should be installed
  on the target site as well: the plugin settings are transferred verbatim, but the
  plugins themselves are not.
* The locales of the journal must be installed on the target site (Administration >
  Site settings > Languages). Locales that are missing on the target site are removed from
  the journal settings with a warning; the primary locale of the journal is required.

## Installation

1. Download the release package (`fullJournalTransfer.tar.gz`).
2. Upload it in *Settings > Website > Plugins > Upload a new plugin*, or extract it into
   `plugins/importexport/fullJournalTransfer` and run `php lib/pkp/tools/installPluginVersion.php plugins/importexport/fullJournalTransfer/version.xml`.
3. The plugin registers its import/export filters in the database when it is installed.
   It also checks and repairs the filter registration every time it is run from the command
   line, so a plugin update (new filters) does not need any manual step.

The plugin has no web interface; the plugin page only shows a notice. Everything is done
from the command line in the root directory of the OJS installation.

## Usage

### Export

```bash
php tools/importExport.php FullJournalImportExportPlugin export /path/to/journal.tar.gz <journal_path> [options]
```

`<journal_path>` is the URL path of the journal (e.g. `ojs` in `https://example.org/index.php/ojs`).
A relative archive path is resolved against the current directory, so `public/ojs.tar.gz` is the
`public/` directory of the installation while `/public/ojs.tar.gz` is a directory in the root of
the file system. Do not write the archive into a web-accessible directory (`public/`): it contains
the complete journal including user data and password hashes. The directory must be writable
by the user running PHP.

### Import

```bash
php tools/importExport.php FullJournalImportExportPlugin import /path/to/journal.tar.gz <user_name> [options]
```

`<user_name>` is an existing user of the target site (typically the site administrator);
the user is recorded as the actor of the import in the event log entries that the import
itself creates. The journal is created with the path it had on the source site unless
`--journal-path` is given. A journal with the same path must not exist on the target site.

The whole import runs in **one database transaction**: if anything fails, all database
changes are rolled back and the files written for the new journal are removed. Warnings
(e.g. a user that could not be found) are printed at the end and do not stop the import.
Progress is printed every 100 users and every 25 submissions; as a reference, a journal with
870 articles, 3,000 files and 2,100 users imports in 3-7 minutes on a Linux server.

### Options

| Option | Effect |
| --- | --- |
| `--no-metrics` | Do not export / import the usage statistics. |
| `--no-activity-log` | Do not export / import the activity log (event log and e-mail log) of the submissions. |
| `--no-public-files` | Do not export / import the public files of the journal (logos, favicon, style sheet, issue and article covers, category and highlight images). |
| `--journal-path <path>` (import) | Create the journal with this URL path instead of the exported one. `--journal-path=<path>` works as well. |

The options apply to the command they are given with: an archive exported with
`--no-metrics` simply contains no statistics; `--no-metrics` on the import ignores
statistics that are in the archive.

### Hints

* Large journals need a lot of memory: run the commands with `php -d memory_limit=-1 ...`.
* Make a database backup of the target site before the import (the transaction protects
  against partial imports, not against mistakes).
* After the import, rebuild the search index of the target site:
  `php tools/rebuildSearchIndex.php` (the search index is not part of the archive).
* The import sends no e-mails and never changes passwords (see *Users* below).
* The XML of the archive is validated against the plugin schema (`fullJournal.xsd`) before
  the import starts. The export also validates the document it produced; if the validation
  fails, the XML is saved next to the archive as `<archive>.invalid.xml` for inspection.

## Archive layout

```
journal.tar.gz
├── <journal_path>.xml       the journal (fullJournal.xsd: native OJS XML + extensions)
├── journals/<id>/           files directory of the journal (submission files, issue galleys)
├── contexts/<id>/library/   publisher library files
├── public/journals/<id>/    public files (logos, covers, ...)
└── metrics/<table>.csv      usage statistics, one CSV per metrics table
```

Files are referenced from the XML by their path inside the archive; nothing is embedded
in the XML (base64), so the XML stays reasonably small even for large journals.

## What is transferred

Journal level:

* journal settings (every row of `journal_settings`, including settings added by plugins),
  plugin settings of the journal (`plugin_settings`), the current issue, custom issue order,
* user groups (roles) with all their properties, settings and stage assignments; the
  automatic section/category editor assignments,
* users: all users enrolled in the journal and every user referenced by the editorial
  workflow of its submissions (see *Users* below), with their roles in the journal, profile
  data, reviewer interests and their validity dates,
* sections, categories (with their hierarchy and images), review forms and elements,
  genres (file types), announcement types and announcements, homepage highlights,
  navigation menus and menu items, e-mail templates (custom and modified ones),
* DOIs with their registration status and settings (e.g. registration agency data),
* publisher library files (journal level and submission level),
* institutions (for the institutional COUNTER statistics), including their IP ranges,
* public files of the journal.

Issues and articles (via the native OJS import/export with extensions):

* issues with galleys, covers, custom section order, table of contents,
* articles with all publication versions (each version keeps its own issue), metadata,
  contributors, keywords, citations, galleys, publication categories, covers, DOIs,
* all submission files with all their revisions (submission, review, revision, copyediting,
  production, dependent files, reviewer attachments, discussion attachments),
* editorial workflow: stage assignments (participants), review rounds and their status,
  review assignments with everything a reviewer did (dates, recommendation, review form
  responses, comments for authors and editors, attachments), editor decisions, discussions
  (queries) with their participants, notes and files, suggested reviewers,
* activity log: the event log of every submission (with its settings, e.g. the file name)
  and the e-mail log including the recipients; the log entries that the import itself
  generates are replaced by the original ones so the history looks like on the source
  site. Users in log entries are remapped by e-mail; IDs of files, submission files and
  review assignments inside log entries are remapped as well,
* submission dates (date submitted with time, last modified, last activity), issue
  dates and last-modified stamps,
* usage statistics: all `metrics_*` tables of OJS 3.4/3.5 (`metrics_context`,
  `metrics_issue`, `metrics_submission`, `metrics_submission_geo_daily/monthly`,
  `metrics_counter_submission_daily/monthly`, `metrics_counter_submission_institution_daily/monthly`),
  with the issue, galley, submission, submission file and institution IDs remapped.

### Users

Users are site-wide objects in OJS, so the import matches them by **e-mail address**:

* If a user with the same e-mail exists on the target site, that account is reused: it
  gets the roles of the journal, its profile data and password are not changed.
* Otherwise a new account is created with the exported data. If the user name is already
  taken on the target site, a numeric suffix is appended (`jsmith` → `jsmith1`).
  Password hashes are copied as they are, so users log in with their old password.
  The only exception are accounts with a legacy (OJS 2.x `md5`/`sha1`) hash, which is bound
  to the user name and hashing algorithm: when such a user name had to be changed (or the
  target site uses another `encryption` setting), the user has to use "Forgot your
  password?" once; these users are listed as warnings at the end of the import.
* All references to users (authors, participants, reviewers, discussion participants,
  decisions, file uploaders, log entries, ...) are remapped to the accounts on the target
  site. References to users that no longer exist on the source site are skipped with a
  warning.

### Not transferred

* site level data: site settings, site plugins and their settings, user notification
  settings of the journal (`notification_subscription_settings`), pending notifications
  (the "tasks" of the editors), invitations,
* subscriptions and payments (`subscription_types`, `subscriptions`, `institutional_subscriptions`,
  `completed_payments`, `queued_payments`),
* the search index (rebuild it after the import), OAI tombstones of deleted objects,
  usage event log files that were not yet processed into the metrics tables
  (`usageStats/` in the files directory), scheduled task logs, temporary files, sessions.

### IDs and URLs

All database IDs are new on the target site. The import writes a mapping of the old to
the new IDs of submissions, publications, issues, galleys, sections, categories, user
groups, review forms, navigation menu items, DOIs, institutions and library files into
`journal_<new id>_id_relation.txt` in the files directory of the new journal, so external
references (URLs with article IDs, DOIs with ID-based suffixes, ...) can be redirected.
URL paths of articles and issues (`url_path`) are kept.

## Troubleshooting

* The plugin builds on the native import/export plugin and the users import/export of
  OJS. If an article cannot be exported, try the native plugin first
  (`php tools/importExport.php NativeImportExportPlugin export ...`).
* Submissions that cannot be represented in the XML (unfinished submission wizard, no
  title) are skipped with a message during the export. Irregular contributor and galley data
  often found in journals migrated from older OJS versions (contributors without a given name
  or without a valid user group, affiliations without a name, galleys without a label or
  whose file is missing) is repaired on the fly and reported as a warning, so the export
  does not stop on it.
* If the exported XML does not validate, every validation error is printed with the issue or
  article it belongs to and the XML is saved as `<archive>.invalid.xml`.
* `Instantiation of the plugin ... has failed` during the import comes from OJS loading
  all plugins to install their defaults for the new journal; a plugin that is broken or
  not completely installed (e.g. missing `vendor/` directory) prints this and is skipped.
* Filter errors like `No filter found for "extended-article=>native-xml"` mean that the
  filter registration in the database is incomplete; running any export/import command
  repairs it, or re-run `php lib/pkp/tools/installPluginVersion.php plugins/importexport/fullJournalTransfer/version.xml`.

## Changes compared to the Lepidus plugin (OJS 3.3 / 3.4 branch)

* Rewritten for OJS 3.5 (PHP 8, Laravel/Eloquent models, repositories, new filter type
  descriptions, new users import/export).
* New: activity log (event log and e-mail log), usage statistics of OJS 3.4+, DOIs with
  registration status, categories, publisher library, highlights, institutions, user groups
  with all properties, generic journal and plugin settings, public files, articles that are
  not assigned to any issue (declined, in the workflow), several publication versions in
  different issues, submission file revisions, review round status, suggested reviewers,
  section/category editor assignments.
* Users keep their passwords; existing accounts on the target site are reused by e-mail.
* The import runs in a transaction and cleans up after itself when it fails.
* The archive keeps the files in their original directory structure (no base64 in the XML)
  and is created/extracted in pure PHP (no `tar` binary needed, works on Windows).
* The automated tests of the Lepidus plugin (PHPUnit, Cypress) were not ported.

## Credits

The original plugin was idealized and sponsored by the Brazilian Institute of Information in
Science and Technology (IBICT) for OJS 2.x; version 3.3 was funded by the Federal University
of São Paulo (Unifesp) and the Federal University of Recôncavo da Bahia (UFRB) and developed
by Lepidus Tecnologia.

The OJS 3.5 version is maintained by academic-journals-cz.

## License

GNU General Public License v3.0

Copyright (c) 2014-2024 Lepidus Tecnologia
Copyright (c) 2025-2026 academic-journals-cz
