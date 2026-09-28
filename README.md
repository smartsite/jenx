# Jen-X

**Ultralite Jenkins™ — Foundd release control**

Jen-X is a deliberately small release controller for the Foundd application.

It provides a simple, auditable way to deploy a specific Git commit to the Foundd UAT environment, run the release checks, restart the application, verify its health, and record the result.

It is **not intended to be a general-purpose CI/CD system**.

## What it does

A release currently follows this sequence:

```text
Git commit/ref
    ↓
Check working tree
    ↓
Fetch origin
    ↓
Resolve commit
    ↓
Checkout exact commit
    ↓
npm ci
    ↓
release:check
    ↓
Restart Foundd
    ↓
Wait for service
    ↓
Health check
    ↓
Record release state
```

A successful release records:

* Git commit
* UTC release timestamp
* status
* HTTP health-check result

Release history is stored as newline-delimited JSON (NDJSON).

## Directory layout

Jen-X lives separately from the Foundd application:

```text
/var/www/xsmart.site/
├── foundd/
└── jenx/
    ├── index.php
    ├── jenx-release.sh
    ├── logo.jpg
    └── state/
        ├── current.json
        └── releases.log
```

`state/` contains runtime state and is not part of the Git repository.

## Releasing

From the Jen-X directory:

```bash
cd /var/www/xsmart.site/jenx
./jenx-release.sh <git-ref>
```

For example:

```bash
./jenx-release.sh 7a2f8c82b61258d5f918780e909655590ecd8677
```

The release script refuses to deploy if the Foundd working tree is dirty.

The deployment is based on the resolved Git commit rather than whatever happens to be at the end of a branch at restart time.

## Release checks

Jen-X runs Foundd's:

```bash
npm run release:check
```

before restarting the application.

This currently includes:

* TypeScript checking
* ESLint
* Prettier check
* test coverage
* production build
* security audit

If these checks fail, the running application is **not restarted**.

## Health check

After restarting Foundd, Jen-X waits for the systemd service to become active and then checks:

```text
http://127.0.0.1:5000/api/health
```

The release is only recorded as successful after the health check returns HTTP 200.

## Release state

The current successful release is stored in:

```text
state/current.json
```

Release history is appended to:

```text
state/releases.log
```

The dashboard reads these files and displays the current release and release history.

## Permissions

The Foundd service restart is permitted through a narrowly scoped sudoers rule so that Jen-X does not require an interactive password.

The release script uses:

```bash
sudo -n systemctl restart foundd.service
```

`-n` ensures the script fails rather than waiting for an interactive sudo password if the permission is missing.

## Dashboard

Jen-X's read-only dashboard is available at:

```text
https://foundd.xsmart.site/jenx/
```

Access is protected by the Foundd/UAT nginx HTTP authentication.

## Design principles

Jen-X is intentionally boring.

* No database
* No framework
* No Node runtime for the dashboard
* No deployment API
* No Docker
* No unnecessary abstractions
* Shell handles deployment
* PHP handles presentation
* JSON/NDJSON handles state

The intention is for the release mechanism to remain small enough that its behaviour can be understood by reading the script.

## Planned improvements

Possible future additions, in roughly this order:

1. Record failed releases and the stage at which they failed.
2. Add rollback to the previous successful release.
3. Take a PostgreSQL backup before deployment.
4. Add a release/preflight check command.
5. Improve the dashboard once the underlying release information expands.

These should only be added where they provide a concrete operational benefit. Jen-X is not intended to grow into a full Jenkins replacement.
