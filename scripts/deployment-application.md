# SolaStock application release

`deploy-app-release.sh` deploys the application, not just release metadata. Install the reviewed script root-owned and non-writable at `/usr/local/sbin/solavel-stock-app-deploy`. Supply a clean, pushed source commit, its reviewed actual-live baseline, exact current directory and canonical `RELEASE_SHA`, a staged Finance candidate, and the signed schema descriptor.

The default builds without activation. `--activate` also requires provisioning and unit-conversion maintenance flags and a root-sealed readiness proof under root-owned non-writable parents. The proof binds `stock_sha`, signed `foundation_content_id`, `status: PASS`, `failed_clients: []`, and the verified nonnegative integer `applied_unit_events`. Existing unit events require an epoch-compatible rollback release. Do not override these controls.

Provision schema separately through the Central signed, additive maintenance process. During first coordinated rollout keep unit conversions paused, provision and verify the correct tenant cohorts, activate Stock against the staged Finance candidate, then activate Finance. Verify web, dedicated workers and signed Finance–Stock transport before removing the pause. Durable retries and acknowledgement recovery remain available while new conversions are paused.

The deployer preserves shared environment/storage, validates actual deployed source, compiles locked dependencies/assets, runs schema preflight, verifies eight consecutive FPM identities, gracefully refreshes the dedicated worker, checks public routes, and restores the prior pointer on activation failure. Three complete application releases are retained. An application rollback does not reverse schema or economic events; never downgrade to code unable to interpret committed unit epochs.

Run `python3 scripts/test-app-deployer.py` as an unprivileged user for isolated filesystem fixtures. It never contacts production services or a database. Test-only free-space simulation is accepted only inside a canonical `/tmp/solastock-app-deploy-test-*/` fixture and cannot bypass production checks.
