from pathlib import Path
src = Path('.stage4-tmp/DB_MIG_002_MACHINE_PATCH.py').read_text()
old = "rep('  status: PASS_DB_MIG_001\\n', '  status: PASS_DB_MIG_002\\n')"
new = "rep('preservation_gate:\\n  status: PASS_DB_MIG_001\\n', 'preservation_gate:\\n  status: PASS_DB_MIG_002\\n')"
if src.count(old) != 1:
    raise SystemExit('unexpected DB-MIG-002 patch helper shape')
src = src.replace(old, new, 1)
exec(compile(src, 'DB_MIG_002_MACHINE_PATCH_V2', 'exec'))
