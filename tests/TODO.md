# Testing TODOs (PMSS)

Purpose: Track planned and hard/important tests to implement next. Keep this in sync with docs and ADRs.

Hermeticity & Determinism
- Introduce `PMSS_TEST_MODE=1` toggle to disable jitter/sleeps and force temp paths for any long-running routines.
- Ensure all tests use per-run temp directories; avoid cross-test state via env seeding.

Update Flow Smoke
- Add a dev-safe smoke script that runs `scripts/update.php --dry-run` with hermetic env and temp paths; assert JSON events and step ordering.
- Validate that no filesystem mutation occurs in `--dry-run` mode; capture logs under `/tmp/pmss-tests-root/`.

Docblocks (Tighten Coverage)
- Make `docblock-lint.sh` required for `scripts/lib/update/**` as the first gating step (keep advisory elsewhere initially).
- Track coverage (classes/public methods) and expand to `scripts/lib/**` once violations are resolved.
- Add CI job to run docblock lint in required mode for selected directories.

Naming & Lints
- Expand camelCase filename lint coverage directory-by-directory.
- Add opt-in class/file naming lint across first-party libs (one class per file, name matches file).
- Enforce no-aliases policy on env keys via advisory lint.
 - Plan rollout: enable `classname-lint.sh` in CI as advisory, then required per-directory once cleaned.
 - #TODO Flip sharp-edges and net-edges lints to strict in CI once the tree is clean (set `PMSS_LINT_SHARP_STRICT=1`, `PMSS_LINT_NET_STRICT=1`). (GH #134)

Sharp/Net Edges
- Make sharp-edges and net-edges lints strict in CI once the tree is clean.
- Extend net-edges lint to detect non-wrapped HTTP calls in PHP (e.g., `file_get_contents('http://...')`) when appropriate.
 - Add a central HTTP helper (wrapping curl) so all outbound calls flow through `runStep()` and consistent logging.

Static Analysis
- Raise phpstan level in stages; document suppression policies.
 - #TODO Add per-directory phpstan configs to raise to level 2 for `scripts/lib/update/**` first (advisory), then expand. (GH #135)

Observability
- Add unit coverage for JSON event helpers (required fields, timestamps, rc, durations) when accessible.

Security Doctrine Scenarios (Issue #212)
1. Attempt to reuse account password for a service that stores plaintext credentials.
2. Attempt to reuse account password for a service that stores weak hashes (MD5/SHA1).
3. Service supports PBKDF2 but code stores plaintext.
4. Service supports scrypt/bcrypt but code uses MD5 hash.
5. Service accepts hashed password but code writes plaintext to config file.
6. Service requires token; code reuses SSH password.
7. Service requires API key; code writes it into world-readable file.
8. Service requires secret; code logs it at info level.
9. Service requires secret; code prints it in error output on failure.
10. Service requires secret; code passes it on command line (visible to `ps`).
11. Service requires secret; code exports it to global environment for child processes.
12. Service requires secret; code stores it under `/tmp` without secure perms.
13. Service requires secret; code stores it in backups without redaction.
14. Service requires secret; code stores it in config without 0600/0640 perms.
15. Service requires secret; code leaves it in a world-readable template.
16. Service stores secrets in JSON; code fails to redact before logging.
17. Service has dedicated credential field; code writes account password instead.
18. Service uses random credential; code overwrites it with account password on update.
19. Service uses per-user token; code sets shared token across users.
20. Service uses generated token; code fails to rotate when regenerating config.
21. Logging path includes credential in filename or path string.
22. Failure trace includes credential in stack trace or exception message.
23. Secrets included in diagnostic tarball without masking.
24. Secrets included in JSON event log.
25. Secrets included in `pmss-update.log`.
26. Credentials stored in `/etc/seedbox/config` but file perms remain 0644.
27. Credentials stored in user home with group/world readable perms.
28. Secrets written using non-atomic write and transiently world-readable temp file.
29. Secrets written via `echo` without `umask` hardening.
30. Secrets copied to `/etc/skel` (should never include secrets).
31. Passwords stored in a file with owner not root or user owner mismatch.
32. Service writes plaintext auth; code syncs from account password.
33. Secrets placed in `.bak` backups that are world-readable.
34. Secrets stored in a file placed in a shared group with many tenants.
35. Secrets logged in structured JSON fields without redaction.
36. Secrets sent over HTTP instead of HTTPS in update flow.
37. Secrets embedded in URLs (query strings) that land in logs.
38. Secrets leaked via `set -x` or debug mode.
39. Secrets exposed via `ps` because daemon is started with `--password` arg.
40. Secrets stored in config file readable by `www-data` without need.
41. No CVE research performed for a service with auth changes.
42. CVE research done but findings not recorded in commit/issue.
43. Known CVE allows auth file read; code stores account password there.
44. Known CVE allows RCE; code enables plugin by default without guard.
45. Known CSRF vulnerability; code exposes web UI on `0.0.0.0` without protection.
46. Known path traversal; code writes sensitive files under web root.
47. Known weak default credentials; code fails to rotate defaults.
48. Known brute-force weakness; code enables login without rate limiting or fail2ban.
49. Known insecure protocol; code enables it without explicit opt-in.
50. Known plaintext auth file; code does not generate service-specific credential.
51. Service binds on public interface; code does not document or restrict.
52. Service has admin API; code enables it without auth.
53. Service exposes debug endpoint; code leaves it on by default.
54. Service uses TLS; code disables verification or sets insecure ciphers.
55. Service uses token; code stores it in a publicly accessible path.
56. Service uses encryption key; code writes it to shared logs.
57. Service requires secret; code includes it in crash dump.
58. CVE list identifies upgrade requirement; code does not pin version or plan update.
59. Known downgrade attack; code allows older protocol version.
60. Service offers privileged action; code exposes it to non-admin user.
61. "Service requires plaintext" used to justify account password storage.
62. "It was already like this" used to justify a new exposure.
63. "Internal only" used to justify enabling unauthenticated endpoints.
64. "No time" used to skip CVE research on auth-related change.
65. "Legacy behavior" used to avoid adding service-specific credential.
66. "Only admins use it" used to skip access control checks.
67. "It’s behind NAT" used to skip security review gate.
68. "It’s a test server" used to skip credential separation.
69. "The package does it" used to justify unsafe defaults.
70. "We can fix later" used to ship without attack surface analysis.
71. Exposure analysis ignores SSH access blast radius for leaked credential.
72. Exposure analysis ignores web UI takeover impact.
73. Exposure analysis ignores API key reuse across services.
74. Exposure analysis ignores pivot risk from service account to other users.
75. Exposure analysis ignores filesystem read leading to privilege escalation.
76. Exposure analysis ignores multi-tenant impact of shared secret.
77. Exposure analysis ignores backup leakage.
78. Exposure analysis ignores log aggregation exposure.
79. Exposure analysis ignores secrets present in core dumps.
80. Exposure analysis ignores transport-layer sniffing when TLS is disabled.
81. Security review checklist missing in verification notes for auth change.
82. Security review does not distinguish account vs service credential.
83. Security review omits file permission check for secret files.
84. Security review omits log/redaction check.
85. Security review omits default bind address check.
86. Security review omits CVE history reference.
87. Security review omits blast radius analysis.
88. Security review omits reasoning for keeping legacy insecure behavior.
89. Security review omits documentation update for credential handling.
90. Security review omits rollback or rotation guidance.
91. Force-load requirement not followed (security docs not read).
92. Force-load requirement followed but not recorded in commit/issue comment.
93. Feature touches encryption but uses deprecated algorithms without opt-in.
94. Feature touches tokens but fails to set expiry/rotation guidance.
95. Feature stores secrets in configs but fails to document permissions.
96. Feature generates random credential but uses weak randomness or short length.
97. Feature uses correct hashing but fails to salt/parameterize properly.
98. Feature uses scrypt/bcrypt but with unsafe cost parameters.
99. Feature rotates credentials but does not restart/reload dependent services.
100. Feature rotates credentials but does not update user-facing docs.

Notes
- Keep tests hermetic: no network/system modifications in dev suite. Use env overrides to inject inputs.
- Production probes remain read-only and should be run separately on live hosts post-deploy.
