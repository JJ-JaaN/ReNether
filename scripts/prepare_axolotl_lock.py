#!/usr/bin/env python3
"""Use an exact-commit public codeload archive for Axolotl's BedrockProtocol.

The upstream Axolotl 5.51.1 lock points to api.github.com/zipball which
may reject a global fine-grained GitHub OAuth token or hit anonymous API
limits. This substitutes the equivalent public codeload URL while keeping
the version, git source SHA, and every other locked dependency unchanged.
Refuses unexpected versions or references rather than silently upgrading.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import sys
import tempfile

NAME = "axolotl-pm/bedrock-protocol"
VERSION = "64.0.0+bedrock-1.26.60"
SHA = "f9d8b40da3940cef8242b0da34ba41fe49ea7364"
API_URL = f"https://api.github.com/repos/axolotl-pm/BedrockProtocol/zipball/{SHA}"
PUBLIC_URL = f"https://codeload.github.com/axolotl-pm/BedrockProtocol/zip/{SHA}"


def adapt(original: str) -> str:
    doc = json.loads(original)
    matches = [p for p in doc.get("packages", []) if p.get("name") == NAME]
    if len(matches) != 1:
        raise ValueError(f"expected exactly one locked {NAME} package")
    p = matches[0]
    if p.get("version") != VERSION or p.get("source", {}).get("reference") != SHA:
        raise ValueError(f"{NAME} version or commit differs from Axolotl 5.51.1")
    dist = p.get("dist", {})
    if dist.get("type") != "zip" or dist.get("reference") != SHA:
        raise ValueError(f"unexpected {NAME} dist metadata")
    if dist.get("url") == PUBLIC_URL:
        return original
    if dist.get("url") != API_URL:
        raise ValueError(f"unexpected {NAME} dist URL; refusing to rewrite")
    if original.count(API_URL) != 1:
        raise ValueError("expected exactly one canonical dist URL in lock file")
    updated = original.replace(API_URL, PUBLIC_URL, 1)
    if json.loads(updated)["packages"] != [
        {**entry, "dist": {**entry["dist"], "url": PUBLIC_URL}} if entry.get("name") == NAME
        else entry for entry in doc["packages"]
    ]:
        raise ValueError("unexpected changes to lock file")
    return updated


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: prepare_axolotl_lock.py <composer.lock>", file=sys.stderr)
        return 2
    path = Path(sys.argv[1])
    try:
        original = path.read_text(encoding="utf-8")
        updated = adapt(original)
        if updated == original:
            print("[ReNether] BedrockProtocol public dist archive already configured")
            return 0
        backup = path.with_name("composer.lock.renether-original")
        if not backup.exists():
            shutil.copy2(path, backup)
        fd, temp = tempfile.mkstemp(prefix=".composer-lock-", dir=path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as stream:
                stream.write(updated)
            os.chmod(temp, path.stat().st_mode & 0o777)
            os.replace(temp, path)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
        print("[ReNether] Pinned BedrockProtocol 26.60 uses public codeload archive")
        return 0
    except (ValueError, OSError, json.JSONDecodeError) as error:
        print(f"[ReNether] ERROR: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
