# ADR-0002: MariaDB 10.4 locally, MySQL 8 in production

- Status: Accepted (2026-10-17)

## Context
The development machine has XAMPP's MariaDB 10.4.32. The spec targets MySQL 8.x. MariaDB 10.4 is end-of-life and differs from MySQL 8 in its JSON type (an alias for LONGTEXT), some locking behaviour, and identifier and default handling.

## Decision
- Develop and test against the local MariaDB using Laravel's `mariadb` driver. Tests do **not** use SQLite.
- Production and CI use **MySQL 8.0+**.
- Write portable migrations: no non-null `TIMESTAMP` without a default (use `DATETIME` for business dates), index names of 64 characters or fewer, and no vendor-specific JSON functions in queries.

## Consequences
CI must run the suite on MySQL 8 before release. Upgrading local development to MySQL 8 (or MariaDB ≥ 10.11) is recommended.
