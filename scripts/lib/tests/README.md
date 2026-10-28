# Test Layout

- `development/` – fast, hermetic tests (run with `php scripts/lib/tests/development/Runner.php`).
- `production/` – post-provision probes intended for live hosts (run manually via
  `php scripts/lib/tests/production/Runner.php`).
- `integration/` – end-to-end tests on live servers (create/destroy real resources,
  run manually as root).
- `common/` – shared utilities such as `TestCase`.

Torrent-client resolver, catalog, proxy, and launch fixtures stage their account
and build files through `TestCase::pmssTorrentClientStoreFixture()`. Callers keep
selection links, projection contents, executable modes, and service settings
explicit so missing capabilities and unavailable selections remain observable.

Keep development tests free of network/system side-effects; production tests may
rely on real services but should remain read-only. Integration tests are
destructive — see `integration/README.md` for safety and requirements.

Fixture trees use `TestCase::pmssWriteFiles($baseDir, $files, $dirMode)` for
ordered relative-path/content maps. It reuses the checked single-file writer,
preserves payload bytes and existing directory modes, and leaves empty maps
untouched. Each caller retains its temporary-root and data-generation policy.

Torrent-client store, launch, stopped-settings and throttle fixtures use
`TestCase::pmssMakeCallbacks()` for fixed operation results, including empty
arrays and null. Pass an event array only when calls must be recorded. Keep
stateful, argument-dependent and throwing callbacks explicit; scalar options
such as build versions and attempt budgets remain ordinary values.

Command-step fixtures use `TestCase::pmssMakeStepRunner($steps, $exitCode)` to
record ordered `[description, command]` pairs and return a fixed status without
executing commands. Log capture uses `pmssMakeArrayLogger($messages)`. Both
retain the caller's array by reference, including when it is reset between
calls. Callbacks with additional side effects or changing results stay explicit.

CLI fixtures use `TestCase::pmssExecArgvCommand()` for literal argument vectors
with environment overrides and captured results. Compound or asynchronous shell
fixtures use `pmssBuildArgvCommand()` for their literal portions; environment
prefixes share `pmssBuildCommandEnvironmentPrefix()`. Keep redirections, working
directories and environment removal at the caller. Run fixtures only on testing
machines, never on the control host.
