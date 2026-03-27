# Workflow & PR Expectations

## Before You Start
- Read `docs/architecture.md`, `docs/update.md`, and relevant ADRs.
- Review the source you plan to touch.

## While Implementing
- Keep changes small and focused; prefer additive evolution.
- Maintain idempotence and fail-soft behavior.
- Update comments and docs to match behavior.

## Validation
- Run local tests:
  - `php -l` on changed PHP files
  - `php scripts/lib/tests/development/Runner.php`
  - `scripts/testing/test-bash.sh` when touching shell scripts
- Capture dry-run rehearsals for updater changes.

## External Communications (Agents)
- Follow `soul.md` for all outbound communications (issues, PRs, discussions, releases, emails).
- Always append the signature `— Sampsa Pellervoinen 🌱` to external comments/messages.
- Commits must include `Co-Authored-By: Sampsa Pellervoinen <noreply@pulsedmedia.com>`.

## PR Checklist
- [ ] Code + tests + docs included
- [ ] ADR added/updated when decisions changed
- [ ] CI passes (see `.github/workflows/ci.yml`)
- [ ] No changes to vendor/third-party trees without approval
