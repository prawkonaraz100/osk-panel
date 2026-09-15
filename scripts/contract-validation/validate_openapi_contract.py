#!/usr/bin/env python3
"""Deterministic contract gate for the OSK v1 OpenAPI specification.

This validator intentionally checks the machine-readable invariants that protect
confirmed reverse-engineered capabilities from disappearing during refactors:
- every YAML contract file parses,
- every root path and every component/schema/parameter/response $ref resolves,
- every canonical HTTP operation has one unique operationId,
- required-operations HTTP rows map bidirectionally to canonical operations,
- shared UI capabilities explicitly reuse an operationId instead of inventing
  duplicate endpoints,
- authentication/security scheme references are valid,
- every non-public operation has an explicit permission annotation,
- request schemas do not expose readOnly response fields.

It is not a substitute for domain/legal/provider tests. It is a structural gate.
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any, Iterable

import yaml


REPO_ROOT = Path(__file__).resolve().parents[2]
API_DIR = REPO_ROOT / "specs" / "api"
ROOT_SPEC = API_DIR / "openapi-v1.yaml"
REQUIRED_OPERATIONS = API_DIR / "required-operations-v1.yml"
HTTP_METHODS = {"get", "put", "post", "delete", "options", "head", "patch", "trace"}


class ContractError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise ContractError(message)


def load_yaml(path: Path, cache: dict[Path, Any]) -> Any:
    path = path.resolve()
    if path in cache:
        return cache[path]
    if not path.exists():
        fail(f"Missing referenced file: {path.relative_to(REPO_ROOT)}")
    try:
        with path.open("r", encoding="utf-8") as handle:
            data = yaml.safe_load(handle)
    except yaml.YAMLError as exc:
        fail(f"YAML parse error in {path.relative_to(REPO_ROOT)}: {exc}")
    if not isinstance(data, dict):
        fail(f"Top-level YAML document must be a mapping: {path.relative_to(REPO_ROOT)}")
    cache[path] = data
    return data


def decode_pointer_segment(segment: str) -> str:
    return segment.replace("~1", "/").replace("~0", "~")


def resolve_pointer(document: Any, pointer: str, source: str) -> Any:
    if pointer in ("", "/"):
        return document
    if not pointer.startswith("/"):
        fail(f"Invalid JSON Pointer in {source}: #{pointer}")
    current = document
    for raw_segment in pointer[1:].split("/"):
        segment = decode_pointer_segment(raw_segment)
        if isinstance(current, dict) and segment in current:
            current = current[segment]
        elif isinstance(current, list) and segment.isdigit() and int(segment) < len(current):
            current = current[int(segment)]
        else:
            fail(f"Dangling JSON Pointer in {source}: #{pointer} (missing segment {segment!r})")
    return current


def resolve_ref(current_file: Path, ref: str, cache: dict[Path, Any]) -> tuple[Path, Any]:
    if not isinstance(ref, str) or not ref:
        fail(f"Invalid empty/non-string $ref in {current_file.relative_to(REPO_ROOT)}")
    if ref.startswith("http://") or ref.startswith("https://"):
        fail(f"Remote $ref is forbidden in canonical contract: {ref}")

    file_part, sep, fragment = ref.partition("#")
    target_file = current_file if not file_part else (current_file.parent / file_part).resolve()
    try:
        target_file.relative_to(REPO_ROOT)
    except ValueError:
        fail(f"$ref escapes repository root: {ref} from {current_file.relative_to(REPO_ROOT)}")

    target_document = load_yaml(target_file, cache)
    pointer = fragment if sep else ""
    target = resolve_pointer(
        target_document,
        pointer,
        f"{current_file.relative_to(REPO_ROOT)} -> {ref}",
    )
    return target_file, target


def walk_refs(node: Any, current_file: Path, cache: dict[Path, Any], seen: set[tuple[Path, str]]) -> int:
    count = 0
    if isinstance(node, dict):
        ref = node.get("$ref")
        if ref is not None:
            key = (current_file.resolve(), ref)
            count += 1
            target_file, target = resolve_ref(current_file, ref, cache)
            if key not in seen:
                seen.add(key)
                count += walk_refs(target, target_file, cache, seen)
        for key, value in node.items():
            if key != "$ref":
                count += walk_refs(value, current_file, cache, seen)
    elif isinstance(node, list):
        for value in node:
            count += walk_refs(value, current_file, cache, seen)
    return count


def root_path_item(root_spec: dict[str, Any], path_key: str, cache: dict[Path, Any]) -> tuple[Path, dict[str, Any]]:
    paths = root_spec.get("paths")
    if not isinstance(paths, dict) or path_key not in paths:
        fail(f"Required path is absent from root OpenAPI: {path_key}")
    item = paths[path_key]
    if not isinstance(item, dict):
        fail(f"Root path item must be a mapping: {path_key}")
    if "$ref" in item:
        target_file, target = resolve_ref(ROOT_SPEC, item["$ref"], cache)
        if not isinstance(target, dict):
            fail(f"Resolved path item is not a mapping: {path_key}")
        return target_file, target
    return ROOT_SPEC, item


def canonical_operations(root_spec: dict[str, Any], cache: dict[Path, Any]) -> dict[tuple[str, str], dict[str, Any]]:
    paths = root_spec.get("paths")
    if not isinstance(paths, dict):
        fail("Root OpenAPI paths must be a mapping")

    result: dict[tuple[str, str], dict[str, Any]] = {}
    operation_ids: dict[str, tuple[str, str]] = {}

    for path_key in paths:
        _, path_item = root_path_item(root_spec, path_key, cache)
        methods_found = 0
        for method, operation in path_item.items():
            method_lc = str(method).lower()
            if method_lc not in HTTP_METHODS:
                continue
            methods_found += 1
            if not isinstance(operation, dict):
                fail(f"Operation must be a mapping: {method_lc.upper()} {path_key}")
            operation_id = operation.get("operationId")
            if not isinstance(operation_id, str) or not operation_id.strip():
                fail(f"Missing operationId: {method_lc.upper()} {path_key}")
            if operation_id in operation_ids:
                previous = operation_ids[operation_id]
                fail(
                    f"Duplicate operationId {operation_id!r}: "
                    f"{previous[0].upper()} {previous[1]} and {method_lc.upper()} {path_key}"
                )
            operation_ids[operation_id] = (method_lc, path_key)
            result[(method_lc.upper(), path_key)] = operation
        if methods_found == 0:
            fail(f"Root path exposes no HTTP operation: {path_key}")
    return result


def validate_root_path_module_parity(root_spec: dict[str, Any], cache: dict[Path, Any]) -> None:
    contract_files = root_spec.get("x-contract-files", {})
    module_refs = contract_files.get("path_modules") if isinstance(contract_files, dict) else None
    if not isinstance(module_refs, list) or not module_refs:
        fail("x-contract-files.path_modules must list canonical path modules")

    root_targets: set[tuple[Path, str]] = set()
    for path_key, item in root_spec.get("paths", {}).items():
        if not isinstance(item, dict) or "$ref" not in item:
            fail(f"Every canonical root path must be a modular $ref: {path_key}")
        ref = item["$ref"]
        file_part, _, fragment = ref.partition("#")
        target_file = (ROOT_SPEC.parent / file_part).resolve()
        pointer = fragment
        expected_pointer = "/paths/" + path_key.replace("~", "~0").replace("/", "~1")
        if pointer != expected_pointer:
            fail(f"Root path $ref pointer mismatch for {path_key}: {ref}")
        resolve_ref(ROOT_SPEC, ref, cache)
        root_targets.add((target_file, path_key))

    module_targets: set[tuple[Path, str]] = set()
    for module_ref in module_refs:
        module_file = (ROOT_SPEC.parent / module_ref).resolve()
        module = load_yaml(module_file, cache)
        module_paths = module.get("paths")
        if not isinstance(module_paths, dict):
            fail(f"Path module has no paths mapping: {module_file.relative_to(REPO_ROOT)}")
        for path_key in module_paths:
            module_targets.add((module_file, path_key))

    missing = module_targets - root_targets
    unexpected = root_targets - module_targets
    if missing:
        fail("Unreferenced modular path(s): " + ", ".join(sorted(f"{p.relative_to(REPO_ROOT)}:{k}" for p, k in missing)))
    if unexpected:
        fail("Root path ref(s) without module path: " + ", ".join(sorted(f"{p.relative_to(REPO_ROOT)}:{k}" for p, k in unexpected)))


def iter_required_http_rows(required: dict[str, Any]) -> Iterable[dict[str, Any]]:
    operations = required.get("operations")
    if not isinstance(operations, dict):
        fail("required-operations-v1.yml operations must be a mapping")
    for group, rows in operations.items():
        if not isinstance(rows, list):
            fail(f"Required-operations group must be a list: {group}")
        for row in rows:
            if not isinstance(row, dict):
                fail(f"Invalid requirement row in group {group}")
            has_method = "method" in row
            has_path = "path" in row
            if has_method != has_path:
                fail(f"HTTP requirement must have both method and path: {group}:{row.get('id')}")
            if has_method:
                yield row


def validate_required_coverage(required: dict[str, Any], canonical: dict[tuple[str, str], dict[str, Any]]) -> tuple[int, int]:
    rows = list(iter_required_http_rows(required))
    mapped_operation_ids: list[str] = []
    seen_requirement_ids: set[str] = set()

    for row in rows:
        requirement_id = row.get("id")
        method = str(row.get("method", "")).upper()
        path = row.get("path")
        if not isinstance(requirement_id, str) or not requirement_id:
            fail(f"HTTP requirement missing id: {method} {path}")
        if requirement_id in seen_requirement_ids:
            fail(f"Duplicate HTTP requirement id: {requirement_id}")
        seen_requirement_ids.add(requirement_id)
        operation = canonical.get((method, path))
        if operation is None:
            fail(f"Required HTTP capability has no canonical OpenAPI operation: {requirement_id} -> {method} {path}")
        operation_id = operation["operationId"]
        alias_target = row.get("covered_by_operationId")
        if alias_target is not None:
            if alias_target != operation_id:
                fail(
                    f"Shared capability mapping mismatch for {requirement_id}: "
                    f"declares {alias_target}, canonical operation is {operation_id}"
                )
        else:
            direct_requirement = operation.get("x-requirement-id")
            covers_one = operation.get("x-covers-requirement")
            covers_many = operation.get("x-covers-requirements", [])
            if direct_requirement != requirement_id and covers_one != requirement_id and requirement_id not in covers_many:
                fail(
                    f"Requirement {requirement_id} maps to {operation_id}, but the operation does not declare it"
                )
        mapped_operation_ids.append(operation_id)

    canonical_ids = {operation["operationId"] for operation in canonical.values()}
    mapped_ids = set(mapped_operation_ids)
    missing = canonical_ids - mapped_ids
    extra = mapped_ids - canonical_ids
    if missing:
        fail("Canonical operation(s) absent from required-operations inventory: " + ", ".join(sorted(missing)))
    if extra:
        fail("Required-operations maps to unknown operationId(s): " + ", ".join(sorted(extra)))

    coverage = required.get("coverage_status", {})
    if isinstance(coverage, dict):
        expected_rows = coverage.get("http_requirement_rows")
        expected_unique = coverage.get("unique_openapi_path_method_operations_required")
        expected_shared = coverage.get("shared_capability_rows_reusing_existing_operationId")
        if expected_rows is not None and expected_rows != len(rows):
            fail(f"Frozen HTTP requirement count changed: expected {expected_rows}, actual {len(rows)}")
        if expected_unique is not None and expected_unique != len(canonical):
            fail(f"Frozen canonical operation count changed: expected {expected_unique}, actual {len(canonical)}")
        shared_count = sum(1 for row in rows if row.get("covered_by_operationId"))
        if expected_shared is not None and expected_shared != shared_count:
            fail(f"Frozen shared capability count changed: expected {expected_shared}, actual {shared_count}")

    return len(rows), len(canonical)


def validate_security(root_spec: dict[str, Any], canonical: dict[tuple[str, str], dict[str, Any]]) -> None:
    root_security = root_spec.get("security")
    schemes = root_spec.get("components", {}).get("securitySchemes", {})
    if not isinstance(schemes, dict) or not schemes:
        fail("Root OpenAPI must define securitySchemes")

    for (method, path), operation in canonical.items():
        effective_security = operation["security"] if "security" in operation else root_security
        if effective_security is None:
            fail(f"Operation has no explicit or inherited security contract: {method} {path}")
        if not isinstance(effective_security, list):
            fail(f"Security must be a list: {method} {path}")

        if effective_security == []:
            tags = operation.get("tags", [])
            is_public_auth = isinstance(tags, list) and "Auth" in tags
            has_compensating_permission = isinstance(operation.get("x-permission"), (str, list))
            if not is_public_auth and not has_compensating_permission:
                fail(f"Public/no-session operation lacks documented compensating security: {method} {path}")
        else:
            for requirement in effective_security:
                if not isinstance(requirement, dict):
                    fail(f"Invalid security requirement: {method} {path}")
                for scheme_name in requirement:
                    if scheme_name not in schemes:
                        fail(f"Unknown security scheme {scheme_name!r}: {method} {path}")
            if "x-permission" not in operation:
                fail(f"Non-public operation lacks x-permission: {method} {path}")


def request_schemas(canonical: dict[tuple[str, str], dict[str, Any]]) -> Iterable[tuple[str, str, dict[str, Any]]]:
    for (method, path), operation in canonical.items():
        body = operation.get("requestBody")
        if not isinstance(body, dict):
            continue
        content = body.get("content")
        if not isinstance(content, dict):
            continue
        for media_type, media in content.items():
            if isinstance(media, dict) and isinstance(media.get("schema"), dict):
                yield f"{method} {path} [{media_type}]", path, media["schema"]


def schema_has_readonly(schema: Any, current_file: Path, cache: dict[Path, Any], visited: set[tuple[Path, str]]) -> bool:
    if isinstance(schema, dict):
        if schema.get("readOnly") is True:
            return True
        ref = schema.get("$ref")
        if isinstance(ref, str):
            key = (current_file.resolve(), ref)
            if key in visited:
                return False
            visited.add(key)
            target_file, target = resolve_ref(current_file, ref, cache)
            if schema_has_readonly(target, target_file, cache, visited):
                return True
        for key, value in schema.items():
            if key != "$ref" and schema_has_readonly(value, current_file, cache, visited):
                return True
    elif isinstance(schema, list):
        return any(schema_has_readonly(value, current_file, cache, visited) for value in schema)
    return False


def validate_request_readonly(root_spec: dict[str, Any], canonical: dict[tuple[str, str], dict[str, Any]], cache: dict[Path, Any]) -> None:
    # Resolve each operation from the root again so relative request-schema refs use
    # the correct module file rather than the root file.
    for path_key in root_spec.get("paths", {}):
        operation_file, path_item = root_path_item(root_spec, path_key, cache)
        for method, operation in path_item.items():
            method_lc = str(method).lower()
            if method_lc not in HTTP_METHODS or not isinstance(operation, dict):
                continue
            body = operation.get("requestBody")
            if not isinstance(body, dict):
                continue
            content = body.get("content", {})
            if not isinstance(content, dict):
                continue
            for media_type, media in content.items():
                if not isinstance(media, dict) or not isinstance(media.get("schema"), dict):
                    continue
                if schema_has_readonly(media["schema"], operation_file, cache, set()):
                    fail(f"Request schema exposes a readOnly field: {method_lc.upper()} {path_key} [{media_type}]")


def main() -> int:
    cache: dict[Path, Any] = {}
    try:
        root_spec = load_yaml(ROOT_SPEC, cache)
        required = load_yaml(REQUIRED_OPERATIONS, cache)

        if root_spec.get("openapi") != "3.1.0":
            fail("Canonical root specification must declare openapi: 3.1.0")

        # Parse and recursively resolve every $ref reachable from canonical files.
        contract_files = root_spec.get("x-contract-files", {})
        declared_files: list[Path] = [ROOT_SPEC, REQUIRED_OPERATIONS]
        if isinstance(contract_files, dict):
            for key in ("components", "settings_components"):
                value = contract_files.get(key)
                if isinstance(value, str):
                    declared_files.append((ROOT_SPEC.parent / value).resolve())
            for value in contract_files.get("path_modules", []) or []:
                declared_files.append((ROOT_SPEC.parent / value).resolve())

        total_refs = 0
        seen_refs: set[tuple[Path, str]] = set()
        for file_path in declared_files:
            document = load_yaml(file_path, cache)
            total_refs += walk_refs(document, file_path, cache, seen_refs)

        validate_root_path_module_parity(root_spec, cache)
        canonical = canonical_operations(root_spec, cache)
        http_requirement_rows, canonical_count = validate_required_coverage(required, canonical)
        validate_security(root_spec, canonical)
        validate_request_readonly(root_spec, canonical, cache)

        print("API contract gate: PASS")
        print(f"  YAML documents parsed: {len(cache)}")
        print(f"  canonical HTTP operations: {canonical_count}")
        print(f"  HTTP requirement rows: {http_requirement_rows}")
        print(f"  unique $ref edges checked: {len(seen_refs)}")
        print(f"  total $ref occurrences traversed: {total_refs}")
        return 0
    except ContractError as exc:
        print(f"API contract gate: FAIL\n  {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
