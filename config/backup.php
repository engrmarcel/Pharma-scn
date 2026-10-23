<?php

/*
| Logical database backups and restore drills (Phase 10c). Managed database snapshots with
| point-in-time recovery remain the primary backup in production (DEPLOYMENT_GUIDE.md);
| `ops:backup` adds an independent, portable copy, and `ops:restore-drill` proves that a backup
| can actually be restored, measuring how long it takes (RTO) and how old the data is (RPO).
*/

return [
    // Turn on scheduling and monitoring of backups and drills.
    'enabled' => (bool) env('BACKUP_ENABLED', false),

    // Where backups are written: a filesystem disk (use a private bucket in another account in production).
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups'),

    // Backups older than this are deleted, but the newest `keep_minimum` are always kept.
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'keep_minimum' => 3,

    // Client binaries (XAMPP: C:\xampp\mysql\bin\mysqldump.exe and mysql.exe).
    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
    'mysql' => env('MYSQL_CLIENT_PATH', 'mysql'),

    // Tables whose rows come and go (not compared after a restore).
    'volatile_tables' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'idempotency_keys',
        'operational_heartbeats', 'operational_alerts', 'recovery_drills', 'personal_access_tokens', 'password_reset_tokens'],

    // Alert thresholds (hours since the last successful backup, days since the last successful drill).
    'backup_max_age_hours' => ['warning' => 26, 'critical' => 50],
    'drill_max_age_days' => ['warning' => 100, 'critical' => 200],
];
