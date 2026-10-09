#!/usr/bin/env python3
import hashlib,json,pathlib,re,sys
root,manifest,commit=pathlib.Path(sys.argv[1]),pathlib.Path(sys.argv[2]),sys.argv[3]
assert re.fullmatch(r'[a-f0-9]{40}',commit), 'Exact Finance commit required'
assert root.is_dir() and root.resolve()==root and not root.is_symlink(), 'Companion must be physical source-only directory'
m=json.loads(manifest.read_text());assert m['commit']==commit
files={}
for p in root.rglob('*'):
 assert not p.is_symlink(), 'Companion symlink prohibited'
 if p.is_file():
  rel=p.relative_to(root).as_posix()
  assert rel=='composer.lock' or rel.startswith('database/migrations/finance/'), 'Companion contains non-schema files'
  files[rel]=hashlib.sha256(p.read_bytes()).hexdigest()
assert files==m['files'], 'Finance companion byte mismatch'
for name in ['2026_10_07_187000_create_financial_origin_intents.php','2026_10_07_192000_create_financial_origin_reverse_generations.php','2026_10_08_194000_create_financial_origin_physical_operations.php','2026_10_08_210000_add_cash_sale_source_to_refund_receipts.php','2026_10_08_219000_create_cash_refund_demand_intents.php']:
 assert 'database/migrations/finance/'+name in files, 'Required native companion schema missing'
print('PINNED_FINANCE_COMPANION=PASS commit='+commit)
