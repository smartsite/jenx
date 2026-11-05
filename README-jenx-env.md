# Jen-X environment controller v1 skeleton

Files:

- `jenx-env-state.php` — schema validation, safe JSON persistence, locks, validated-build lookup, operation lifecycle and shared eligibility rules.
- `jenx-env.php` — CLI interface and read-only status/actions/operation commands.
- `test-jenx-env.php` — offline tests using isolated temporary directories.

## Run the tests

```bash
php -l jenx-env-state.php
php -l jenx-env.php
php test-jenx-env.php
```

## CLI examples

```bash
php jenx-env.php status --json
php jenx-env.php status dev
php jenx-env.php actions uat
php jenx-env.php actions dev sha256:<64-hex-digest>
php jenx-env.php operation <operation-uuid> --json
```

`deploy`, `promote`, and `rollback` validate preconditions but deliberately return `DEPLOYMENT_ADAPTER_UNAVAILABLE` until the Docker/Compose adapter is implemented. They do not create operation records or modify environment state in this skeleton.

The default paths match the established host layout:

- Environment state: `/var/www/jenx/state/environments`
- Existing build records: `/var/www/jenx/state/builds`

The two `JENX_*_STATE_DIRECTORY` environment variables exist for isolated tests and can be used for staging. Do not set them in production without a deliberate configuration decision.

## Deployment safety notes

- A deployment candidate must match a `SUCCEEDED` build record whose validated result contains the matching digest and `test_only: false`.
- State writes use same-directory temporary files and atomic rename; files are set to mode `0640` and directories to `0750` when created.
- Per-environment lock files are persistent and must not be deleted as a lock-release mechanism.
- Successful operation transition requires both `image_digest_matches: true` and `application_healthy: true`.
- No Docker commands, network calls, web endpoints, or changes to existing build records are implemented here.
