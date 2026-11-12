# 2.0.190.1 — after-sales filter warnings (Development)

Defines the two after-sales date input display values before the form renders, using sanitized original request values. Existing SQL filter normalization and financial logic are unchanged.

The supplied log also contains host-level permission errors, cron storage failures and database Commands out of sync. This code patch does not repair server file ownership, MySQL connections or cron configuration. The installable ZIP contains no error_log and all regular-file permissions are 0644. A non-writable error_log in the installed plugin directory must be backed up and addressed by the site owner/host before the WordPress upgrader can proceed.

PHP runtime/WordPress/MySQL validation remains required. No new migration or financial changes.
