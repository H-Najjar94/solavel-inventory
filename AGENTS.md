# SolaStock agent instructions

<!-- solavel-agent-rules v1 (2026-11-22). Canonical source: H-Najjar94/solavel docs/agents/SOLAVEL_AGENT_RULES.md. Keep every copy identical. -->
## Mandatory Solavel agent rules

These rules apply to every agent (Claude, Codex, others) working on the Solavel server or in any Solavel repository.

### 1. Use dedicated Solavel test accounts

- For browser tests, application sign-in, SSO, API verification, screenshots and performance checks, use only the approved Solavel Test Company and Solavel Test User accounts in the shared QA register: `docs/runbooks/qa-test-accounts.md` in H-Najjar94/solavel (on this host also `/home/hnajjar/SOLAVEL_QA_REGISTER.md`).
- Default to Solavel Test Company 01. Use another registered test company only when its data, configuration or permissions are needed. Use the lowest test role that can exercise the workflow.
- Never use real customer accounts, Gmail accounts, the owner's o.a.alkhazali accounts, or the owner's manual test login ("Solavel Test User 01") for agent testing. Use the dedicated agent test logins.
- Read credentials only through the existing protected handoff (the 0600 credential files named in the register). Keep secrets out of source, logs, screenshots and reports.
- Reuse standing accounts. Create disposable accounts only when the test itself exercises signup or provisioning, and remove what that test creates. If the right test login is unavailable, continue the work that does not need sign-in and report the blocked checks.
- Authorized deployments and server administration keep using the existing operational identities and runbooks. Those identities are for operations only, never for application testing.

### 2. Keep all task work under /var/tmp/solavel

- Create one unique directory per task: `/var/tmp/solavel/<task>-<yyyymmdd>-<random>/`. Put every temporary checkout, clone, Git worktree, build, export, browser profile, screenshot, trace, test database and task-generated cache there. Set `TMPDIR` (and tool output paths such as Playwright/Puppeteer profiles, `npm_config_cache`, `COMPOSER_CACHE_DIR` when task-specific) to that directory.
- Create and manage worktrees from the canonical repository (GitHub `H-Najjar94/<repo>`; clone it into the task directory when no canonical local checkout exists). Edit and build inside the worktree under `/var/tmp/solavel/`. Deploy only with the standard deployer. Never edit live release paths (`/var/www/solavel-*/releases`, `current`) or service storage; those belong to their services.
- `/var/tmp` is disposable. Before cleanup, put required source changes (pushed branch / merged PR), review artifacts and handoff notes in their durable destination and verify they are retrievable there. Never leave the only copy of important work in `/var/tmp`.
- On success, failure or cancellation: stop task-owned processes, remove task-owned worktrees with `git worktree remove` (then `git worktree prune`), remove the task directory, and verify both the path and the worktree registration are gone. Clean up only the exact directory this task owns. For pre-existing leftovers, establish ownership and recoverability before removing anything.

### 3. Measure performance and control storage growth

- For performance-sensitive changes, measure the affected workflow before and after with the same QA account, dataset and conditions. Use representative data. Record the measurement window, sample count and exactly what was measured. Keep cold and warm results apart when relevant. Report anything unmeasured as unverified.
- Keep production performance checks lightweight: targeted requests and existing metrics. Production load tests and large scans need separate approval.
- Successful polls, heartbeats and no-op jobs use existing counters or bounded telemetry. Do not write a database row or event per cycle, and avoid redundant writes. Keep individual audit actions, permission denials, security events and actionable failure details. Keep operational logs bounded with the existing rotation and retention.
- Treat CPU, latency, database writes and disk use as separate measurements. For a high-frequency worker or logging change, verify the write rate or log growth with real observations. Claim only what the measurements support.
