#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
status=0

echo "SolaStock user-facing English scan"
echo "Root: $root"

# JSX text nodes and common prop literals. This is intentionally conservative:
# it reports candidates for review; it never treats customer data or technical
# identifiers as translatable source text.
# Narrow reviewed allowlist:
# - OnboardingPage.jsx `migrated_at_inv`: an immutable tenant migration marker
#   shown to administrators; translating it would make the server command wrong.
# - ItemsPage.jsx `<th>SKU</th>`: internationally recognized inventory
#   abbreviation; the underlying identifier and its heading remain exact.
if rg -n --glob '*.jsx' --glob '*.js' \
  '<[^>]+>[[:space:]]*[A-Za-z][A-Za-z ,./&+#?()’'"'"'_-]{2,}[[:space:]]*<' \
  "$root/resources/js/solastock/pages" "$root/resources/js/solastock/components" \
  | rg -v 'OnboardingPage\.jsx:.*<code>migrated_at_inv</code>' \
  | rg -v 'ItemsPage\.jsx:.*<th>SKU</th>'; then
  status=1
fi

if rg -n --glob '*.php' "['\"][A-Z][A-Za-z ,.'\"-]{8,}[.!?]['\"]" \
  "$root/app/Http" "$root/app/Services" "$root/app/Http/Requests"; then
  status=1
fi

if [[ "$status" -ne 0 ]]; then
  echo
  echo "Unreviewed system-owned English candidates found. Do not deploy as complete."
else
  echo "No candidates found. Verify the technical allowlist and run route tests."
fi
exit "$status"
