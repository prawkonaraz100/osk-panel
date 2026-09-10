#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml

MODULE_PATH = re.compile(r"^(?:app/Modules|resources/js/modules)/([^/]+)/")
ALLOWED_STATUSES = {
    "IMPLEMENTED",
    "SUPERSEDED_BY_LEGAL_RULE",
    "SUPERSEDED_BY_OWN_PRODUCT_DECISION_WITH_EQUIVALENT_CAPABILITY",
    "DEFERRED_OUTSIDE_CORE_WITH_EXPLICIT_DECISION",
    "NOT_A_REQUIREMENT_DEMO_ANOMALY",
}
CHAIN_KEYS = (
    "evidence",
    "requirement",
    "domain_data",
    "api_use_case",
    "permission",
    "ui",
    "acceptance_test",
)


def load_yaml(path: Path) -> dict:
    data = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ValueError(f"{path} must contain a YAML mapping")
    return data


def changed_files(root: Path, base: str | None, head: str | None) -> list[str]:
    if not head:
        head = "HEAD"
    if not base or set(base) == {"0"}:
        probe = subprocess.run(
            ["git", "rev-parse", f"{head}^"], cwd=root, text=True, capture_output=True
        )
        if probe.returncode != 0:
            return []
        base = probe.stdout.strip()
    result = subprocess.run(
        ["git", "diff", "--name-only", f"{base}...{head}"],
        cwd=root,
        text=True,
        capture_output=True,
        check=True,
    )
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def validate_trace(root: Path, module: str, core_modules: set[str]) -> list[str]:
    errors: list[str] = []
    path = root / "specs" / "traceability" / "implementation" / f"{module}.yml"
    if not path.is_file():
        return [f"changed module {module} has no traceability file: {path.relative_to(root)}"]

    try:
        trace = load_yaml(path)
    except Exception as exc:  # noqa: BLE001
        return [f"cannot parse {path.relative_to(root)}: {exc}"]

    if trace.get("module") != module:
        errors.append(f"{path.relative_to(root)}: module must equal {module}")

    refs = trace.get("core_traceability_modules")
    if not isinstance(refs, list) or not refs or not all(isinstance(v, str) and v for v in refs):
        errors.append(f"{path.relative_to(root)}: core_traceability_modules must be a non-empty list")
    else:
        unknown = sorted(set(refs) - core_modules)
        if unknown:
            errors.append(f"{path.relative_to(root)}: unknown core traceability modules: {unknown}")

    scope = trace.get("changed_scope")
    if not isinstance(scope, list) or not scope:
        errors.append(f"{path.relative_to(root)}: changed_scope must be a non-empty list")
        return errors

    for index, row in enumerate(scope, start=1):
        prefix = f"{path.relative_to(root)} changed_scope[{index}]"
        if not isinstance(row, dict):
            errors.append(f"{prefix}: must be a mapping")
            continue
        for key in CHAIN_KEYS:
            value = row.get(key)
            if not isinstance(value, str) or not value.strip():
                errors.append(f"{prefix}: {key} must be a non-empty explicit value")
        if row.get("status") not in ALLOWED_STATUSES:
            errors.append(f"{prefix}: invalid status {row.get('status')!r}")
    return errors


def validate(root: Path, files: list[str]) -> list[str]:
    core = load_yaml(root / "specs" / "traceability" / "core-v1.yml")
    modules = core.get("modules")
    if not isinstance(modules, dict):
        return ["specs/traceability/core-v1.yml must contain modules mapping"]
    changed_modules = sorted(
        {match.group(1) for name in files if (match := MODULE_PATH.match(name))}
    )
    errors: list[str] = []
    for module in changed_modules:
        errors.extend(validate_trace(root, module, set(modules)))
    return errors


def self_test() -> None:
    valid_trace = {
        "module": "IdentityTenant",
        "core_traceability_modules": ["auth_identity"],
        "changed_scope": [
            {
                "evidence": "docs/08-security-compliance.md",
                "requirement": "tenant isolation",
                "domain_data": "organization_memberships",
                "api_use_case": "auth.login",
                "permission": "active_membership",
                "ui": "NOT_APPLICABLE_FOUNDATION_NO_UI",
                "acceptance_test": "IdentityTenantPolicyTest",
                "status": "IMPLEMENTED",
            }
        ],
    }
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        (root / "specs/traceability/implementation").mkdir(parents=True)
        (root / "specs/traceability/core-v1.yml").write_text(
            yaml.safe_dump({"modules": {"auth_identity": {}}}), encoding="utf-8"
        )
        trace = root / "specs/traceability/implementation/IdentityTenant.yml"
        trace.write_text(yaml.safe_dump(valid_trace, sort_keys=False), encoding="utf-8")
        if validate(root, ["app/Modules/IdentityTenant/Policy.php"]):
            raise RuntimeError("positive traceability self-test failed")
        trace.unlink()
        if not validate(root, ["app/Modules/IdentityTenant/Policy.php"]):
            raise RuntimeError("missing-trace negative self-test failed")
        broken = dict(valid_trace)
        broken["changed_scope"] = [dict(valid_trace["changed_scope"][0], permission="")]
        trace.write_text(yaml.safe_dump(broken, sort_keys=False), encoding="utf-8")
        if not validate(root, ["resources/js/modules/IdentityTenant/View.vue"]):
            raise RuntimeError("incomplete-chain negative self-test failed")
    print("CHANGED_MODULE_TRACEABILITY_SELF_TEST=PASS")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0

    root = Path(__file__).resolve().parents[2]
    files = changed_files(root, os.getenv("CI_BASE_SHA"), os.getenv("CI_HEAD_SHA"))
    errors = validate(root, files)
    if errors:
        for error in errors:
            print(f"ERROR: {error}", file=sys.stderr)
        return 1
    modules = sorted({m.group(1) for f in files if (m := MODULE_PATH.match(f))})
    print(f"CHANGED_MODULE_TRACEABILITY=PASS modules={','.join(modules) if modules else 'none'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
