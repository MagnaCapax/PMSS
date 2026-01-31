# Test Layout

- `development/` – fast, hermetic tests (run with `php scripts/lib/tests/development/Runner.php`).
- `production/` – post-provision probes intended for live hosts (run manually via
  `php scripts/lib/tests/production/Runner.php`).
- `integration/` – end-to-end tests on live servers (create/destroy real resources,
  run manually as root).
- `common/` – shared utilities such as `TestCase`.

Keep development tests free of network/system side-effects; production tests may
rely on real services but should remain read-only. Integration tests are
destructive — see `integration/README.md` for safety and requirements.
