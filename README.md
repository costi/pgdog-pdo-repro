# PgDog PDO Repro

This is a standalone repro lab for `pdo_pgsql` behavior against PgDog.

It uses:

- `postgres:16`
- a configurable PgDog image
- `php:7.4-cli` with `pdo_pgsql`

The default PgDog config is intentionally close to the production shape:

- `pooler_mode = "session"`
- `workers = 16`
- a source database and a mirror database
- PgDog mirroring enabled from source to mirror

This lab was used to reproduce a mirror-side `DEALLOCATE pdo_stmt_* does not exist` bug and then verify a local fix in the PgDog source tree.

## Start

Run against the released PgDog image:

```bash
docker compose up -d --build
```

Run against a local patched PgDog checkout:

```bash
docker build -t pgdog-local:debug pgdog
PGDOG_IMAGE=pgdog-local:debug docker compose up -d --build
```

## Direct Postgres

This should succeed:

```bash
docker compose run --rm php env REPRO_HOST=postgres REPRO_DBNAME=app php /app/pdo_repro.php
```

Expected output:

```text
dsn: pgsql:host=postgres;port=5432;dbname=app
emulate_prepares: false
result: success
```

## PgDog Client Baseline

This succeeds from the client side:

```bash
docker compose run --rm php env REPRO_HOST=pgdog REPRO_DBNAME=app php /app/pdo_repro.php
```

Expected output:

```text
dsn: pgsql:host=pgdog;port=5432;dbname=app
worker: worker
mode: query
loops: 1
emulate_prepares: false
result: success
```

Higher concurrency also succeeded from the client side. For example:

```bash
./parallel_repro.sh pgdog app mixed 100 16
```

Observed result:

```text
worker: worker1
mode: mixed
loops: 100
...
result: success
...
worker: worker16
mode: mixed
loops: 100
...
result: success
```

## Fixed Mirror-Side Repro

Before the local fix, the mirror PostgreSQL logs showed:

```text
ERROR:  prepared statement "pdo_stmt_00000001" does not exist
STATEMENT:  DEALLOCATE pdo_stmt_00000001
```

After patching the mirror path to use the effective session-mode prepared-statements level, the same one-client repro now succeeds without producing those mirror-side errors.

## Follow-Up Bug

This repro and the fix above are specifically about the mirror-side session-mode mismatch.

There is likely a second, separate bug still worth exploring later:

- enable source-side prepared statement rewriting for real, for example with transaction pooling
- send PDO traffic that later issues simple SQL cleanup like `DEALLOCATE pdo_stmt_...`
- verify whether PgDog also needs to rewrite or intercept that simple SQL cleanup on the source path

In other words, this repo currently documents and reproduces the mirror bug. It does not yet prove that source-side simple `DEALLOCATE ...` handling is correct once prepared statement rewriting is active on the primary path.

## Parallel Baseline

To run two workers in parallel through PgDog:

```bash
./parallel_repro.sh pgdog app mixed 25
```

Arguments are:

1. host
2. database name
3. mode: `query`, `prepare`, or `mixed`
4. loop count per worker

This is useful for verifying that plain session mode still behaves correctly under light concurrency.

## What The Script Does

The PHP script can run three modes:

1. `query`
   - opens a `PDO` connection
   - runs `SET search_path TO public`
2. `prepare`
   - opens a `PDO` connection
   - runs a simple prepared `SELECT $1::int`
3. `mixed`
   - runs both of the above on each loop iteration

The next step, if needed, is to compare:

- mirroring disabled vs enabled
- session pooling vs transaction pooling
- one PgDog worker vs many PgDog workers
- low PHP concurrency vs high PHP concurrency
- and then test whether simple SQL `DEALLOCATE ...` needs its own rewrite/intercept path
