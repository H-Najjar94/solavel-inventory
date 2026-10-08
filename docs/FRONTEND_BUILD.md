# SolaStock — frontend build & deploy

How to build the SolaStock SPA assets safely on the production host without
touching system Node, `.env`, or DB.

---

## The problem (and why this doc exists)

- Production host system Node: **v18.20.8** (`/usr/local/bin/node`).
- The app uses **Vite 7.3.5** (`package.json`: `vite: ^7.0.0`), which **requires
  Node ≥ 20.19 (or ≥ 22.12)**. So the system Node **cannot** run `vite build`.
- The live SPA is served from **compiled assets** in `public/build/` (referenced
  by `@vite([...])` in `resources/views/solastock-app.blade.php`, which reads
  `public/build/manifest.json`). The app does **not** run Node at request time —
  it serves static built files. So a broken build = broken SPA.

## Chosen strategy: build with a user-local Node 20 via nvm

`nvm` is installed at `~/.nvm`. We build with **Node v20.20.2** (installed via
nvm, satisfies Vite's ≥20.19) **in userspace** — no sudo, and the **system Node
18 is left untouched** (other tooling that depends on it is unaffected). This is
preferred over upgrading system Node (risky, needs sudo, affects everything) and
avoids needing Docker (not installed here).

> Verified: building on Node 20.20.2 runs warning-free; building on the older
> nvm 20.17.0 also works but prints "Vite requires Node 20.19+/22.12+" — use
> 20.20.2 to stay on a supported version.

## Exact build commands

```bash
cd /var/www/html/solavel-inventory

# Load nvm and select the supported Node (userspace; system Node stays 18).
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
# The user's ~/.npmrc sets a prefix that conflicts with nvm, so use --delete-prefix:
nvm use --delete-prefix v20.20.2
node -v   # expect v20.20.2

# (first time on a clean checkout only) install deps:
# npm ci

# Build the SPA assets into public/build/
npm run build      # == vite build
```

Expected tail:
```
✓ 148 modules transformed.
public/build/manifest.json ...
✓ built in ~4s
```

The build is **deterministic**: with unchanged source it produces
**byte-identical** asset hashes (verified via md5sum before/after), so a rebuild
does not change what the live app serves.

## Safe-deploy procedure (because public/build IS the live directory)

`vite build` writes straight into the live `public/build/`. To deploy safely:

```bash
cd /var/www/html/solavel-inventory

# 1. Back up the current working build OUTSIDE the web root (restore point).
mkdir -p storage/build-backups
cp -r public/build storage/build-backups/build.bak-$(date +%F_%H%M)

# 2. Build (commands above).

# 3. Verify the new manifest resolves to real files:
php artisan tinker --execute='$m=json_decode(file_get_contents(public_path("build/manifest.json")),true);
$ok=true;foreach($m as $e){if(!file_exists(public_path("build/".$e["file"]))){echo "MISSING ".$e["file"]."\n";$ok=false;}}
echo $ok?"OK: all manifest assets present\n":"BROKEN\n";'

# 4. Load the SPA page in a browser and confirm it renders (hard-refresh).
```

> Keep backups under `storage/build-backups/` (NOT in `public/`) so they are not
> web-served. A backup left in `public/build.bak-*` would be publicly reachable.

## Rollback

If a build breaks the SPA, restore the last good build:
```bash
cd /var/www/html/solavel-inventory
rm -rf public/build
cp -r storage/build-backups/build.bak-<TIMESTAMP> public/build
```
No DB/`.env`/asset-pipeline state is involved, so rollback is just a directory swap.

## Notes / future improvements
- Optionally pin the build Node in `.nvmrc` (`20.20.2`) so `nvm use` auto-selects it.
- The conflicting `prefix` in `~/.npmrc` is why `--delete-prefix` is required; a
  cleaner fix is to remove that prefix from `~/.npmrc`, but that's a host change —
  left as-is for now.
- A dedicated CI/staging build step (build there, ship `public/build/`) would
  remove building on the live host entirely; not set up yet.
- Do NOT introduce a hard `engine-strict` that would block 20.20.2.
