# PgDog Mirroring Bug Report Draft

## Title

Mirroring uses the raw `general.prepared_statements` level instead of the effective session-mode setting, causing mirror-side `DEALLOCATE pdo_stmt_*` errors

## Summary

We can reproduce a mirror-side PostgreSQL error with a very small local setup:

- one source Postgres
- one mirror Postgres
- PgDog mirroring enabled from source to mirror
- one PHP `pdo_pgsql` client
- `pooler_mode = "session"`

The client request succeeds, but the mirror PostgreSQL logs errors like:

```text
ERROR:  prepared statement "pdo_stmt_00000001" does not exist
STATEMENT:  DEALLOCATE pdo_stmt_00000001
```

This reproduces even with:

- one PDO client
- `pooler_mode = "session"`
- `prepared_statements = "extended"` in config

The important part is that, in session mode, the source/client path effectively behaves as if prepared statements are disabled, while the mirror path was still using the raw configured level.

## Versions

Observed with:

- PgDog `v0.1.32`
- image: `ghcr.io/pgdogdev/pgdog:v0.1.32`
- PostgreSQL `16`
- PHP `7.4-cli` with `pdo_pgsql`

PgDog startup log:

```text
🐕 PgDog v0.1.32 [main@e3a9fe5, pgdog-plugin 0.2.0, rustc 1.94.0 (4a4ef493e 2026-03-02)]
```

## Minimal Repro

Standalone repro directory:

- `compose.yml`
- `pgdog.toml`
- `users.toml`
- `pdo_repro.php`

### `compose.yml`

This uses:

- `postgres`
- `postgres_mirror`
- `pgdog`
- `php`

### `pgdog.toml`

Reproducing config:

```toml
[general]
host = "0.0.0.0"
port = 5432
pooler_mode = "session"
workers = 1
default_pool_size = 100
prepared_statements = "extended"

[[databases]]
name = "app"
host = "postgres"
port = 5432
database_name = "app"

[[databases]]
name = "app_mirror"
host = "postgres_mirror"
port = 5432
database_name = "app"

[[mirroring]]
source_db = "app"
destination_db = "app_mirror"
exposure = 1
queue_length = 12048
```

This config is enough to reproduce the bug.

### `users.toml`

```toml
[[users]]
name = "app"
database = "app"
password = "app"

[[users]]
name = "app"
database = "app_mirror"
password = "app"
```

### PHP repro

The PHP client opens one `PDO` connection and repeatedly runs:

1. `SET search_path TO public`
2. a simple prepared statement: `SELECT $1::int`

Equivalent behavior from the repro script:

```php
$pdo->query('SET search_path TO public');
$stmt = $pdo->prepare('SELECT $1::int');
$stmt->execute([1]);
$stmt->fetchColumn();
```

## Repro Steps

Start the lab:

```bash
docker compose up -d --build --force-recreate
```

Run one client through PgDog:

```bash
docker compose run --rm php \
  env REPRO_HOST=pgdog REPRO_DBNAME=app REPRO_MODE=mixed REPRO_LOOPS=500 REPRO_WORKER=solo \
  php /app/pdo_repro.php
```

Client-side result:

```text
dsn: pgsql:host=pgdog;port=5432;dbname=app
worker: solo
mode: mixed
loops: 500
emulate_prepares: false
result: success
```

Check the mirror PostgreSQL logs:

```bash
docker compose logs postgres_mirror
```

Observed mirror-side result:

```text
ERROR:  prepared statement "pdo_stmt_00000001" does not exist
STATEMENT:  DEALLOCATE pdo_stmt_00000001
ERROR:  prepared statement "pdo_stmt_00000002" does not exist
STATEMENT:  DEALLOCATE pdo_stmt_00000002
...
```

This continues for many generated `pdo_stmt_*` names.

## Exact Root Cause

The asymmetry turned out not to be worker count, concurrency, or `prepared_statements = "full"`.

It is this:

1. In session mode, the client path uses the **effective** prepared-statements setting from:

```rust
config.prepared_statements()
```

2. `ConfigAndUsers::prepared_statements()` disables prepared statements in session mode:

```rust
pub fn prepared_statements(&self) -> PreparedStatements {
    if self.config.general.pooler_mode == PoolerMode::Session {
        PreparedStatements::Disabled
    } else {
        self.config.general.prepared_statements
    }
}
```

3. The mirror path was not using that effective value. It constructed its prepared-statement state with the raw default/configured level instead.

4. So with:

```toml
pooler_mode = "session"
prepared_statements = "extended"
```

the source/client path effectively ran with `Disabled`, while the mirror path still ran with `Extended`.

5. That caused the mirror path to rewrite extended-protocol prepared statement names to `__pgdog_*`, while the source path kept the original PDO names like `pdo_stmt_00000001`.

6. Later, PDO sent simple SQL cleanup:

```sql
DEALLOCATE pdo_stmt_00000001
```

7. The mirror backend only knew the prepared statement under the rewritten `__pgdog_*` name, so PostgreSQL correctly errored:

```text
ERROR:  prepared statement "pdo_stmt_00000001" does not exist
STATEMENT:  DEALLOCATE pdo_stmt_00000001
```

## Important Control Cases

### No mirroring

With PgDog session pooling but **without** mirroring enabled, the client-side repro succeeds and we did not reproduce the mirror-side error.

### Mirroring enabled

With the same basic setup and **mirroring enabled**, the mirror-side `DEALLOCATE pdo_stmt_*` failure appears.

### One client is enough

This reproduces with:

- `workers = 1`
- one PDO client
- `3` or `500` loops

So concurrency is not required.

## Expected Behavior

When mirroring is enabled, the mirror path should use the same **effective** prepared-statements compatibility level as the source/client path. In session mode, that means the mirror should also behave as `Disabled`.

## Actual Behavior

- the source-side client succeeds
- PgDog mirrors the traffic
- the mirror PostgreSQL reports that named prepared statements do not exist when `DEALLOCATE` is replayed
- the problem disappears if the mirror path is patched to use `config.prepared_statements()`

This strongly suggests that the mirror-side replay path is not preserving `pdo_pgsql` extended-protocol prepared statement state correctly.

## Verified Local Fix

This local change fixes the repro:

```rust
fn new(params: &Parameters, config: &ConfigAndUsers) -> Self {
    let mut prepared_statements = PreparedStatements::new();
    prepared_statements.set_level(config.prepared_statements());

    Self {
        id: BackendKeyData::new(),
        prepared_statements,
        // ...
    }
}
```

After that change:

- the regression test passes
- the Docker compose PDO repro still succeeds client-side
- `postgres_mirror` no longer logs `prepared statement "pdo_stmt_*" does not exist`

## Notes

- This issue is not specific to `prepared_statements = "full"`
- One client is enough
- One PgDog worker is enough
- The failing statements are clearly named (`pdo_stmt_*`), so `extended_anonymous` does not look like the likely fix

## Separate Follow-Up Bug

There is likely another bug, separate from this mirror fix:

- if source-side prepared statement rewriting is truly active, for example under transaction pooling,
- and the client later sends simple SQL cleanup like:

```sql
DEALLOCATE pdo_stmt_00000001
```

then PgDog may also need to rewrite or intercept that simple SQL `DEALLOCATE` on the primary/source path.

This report is intentionally narrower:

- it covers the verified mirror-side session-mode mismatch
- it does not claim that simple SQL `DEALLOCATE ...` is already handled correctly in every non-session prepared-statement mode

## Attachments

Relevant local files:

- `compose.yml`
- `pgdog.toml`
- `users.toml`
- `pdo_repro.php`
- optional helper: `parallel_repro.sh`
