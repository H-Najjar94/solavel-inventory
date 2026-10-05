# Keep open workspaces intact

Read failures use nonblocking notices without moving focus or requesting a reload. Authorization, CSRF and expired-session checks continue to reject requests normally. HR and Projects report expiry in place rather than redirecting away from a draft.

Uncertain saves still show a persistent warning and retain duplicate-submission protection. Reviewing their status opens a separate same-origin tab; it never reloads the current form and does not automatically retry writes.

Central and Finance reject stale-version partial Inertia refreshes with a plain JSON 409, without a location header. Users refresh deliberately when ready; ordinary explicit navigation retains the existing version-update protocol.

SolaCount startup ends after the first React view commits, including views without Finance-specific shell markers. Its watchdog stops at that point. Slow boots can recover when React becomes ready after a timeout; optional CSS or later background errors cannot restore the splash. Central also ignores startup failures after readiness.

Regression: run `node tests/Browser/workspace-continuity.mjs` in Finance. Optional WORKSPACE_HR_SOURCE and WORKSPACE_PROJECTS_SOURCE paths exercise the actual API clients with simulated 401/419 responses. WORKSPACE_TEST_DEPENDENCIES may point to a dependency installation containing esbuild and puppeteer. The test preserves a draft through late startup, background errors, read failures, and status review. Unit WorkspaceVersionRefreshTest checks the partial-refresh response protocol in Central and Finance.

Stock retains its previously authorized workspace when an access recheck fails, with controls paused and a nonblocking retry notice. Initial access remains gated. Explicit organization changes clear that retained presentation so previous-organization data is never reused for a new organization. Read errors forwarded through Stock's toast adapter remain nonblocking.
