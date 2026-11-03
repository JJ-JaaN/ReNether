#!/usr/bin/env python3
"""Conservatively enable NetherNet for Bedrock 26.60 without replacing other YAML.

No external PyYAML dependency is needed. This deliberately supports only the
regular, block-style Axolotl transport section and refuses ambiguous variants.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import shutil
import sys
import tempfile


def configure(source: str, port: int) -> str:
    if not 1 <= port <= 65535:
        raise ValueError("port must be between 1 and 65535")
    lines = source.splitlines(keepends=True)
    transport_heads = [
        i for i, line in enumerate(lines)
        if re.match(r"^transport:\s*(?:#.*)?$", line.strip("\r\n"))
    ]
    ambiguous = [
        line for line in lines
        if re.match(r"^transport\s*:", line) and
        not re.match(r"^transport:\s*(?:#.*)?$", line.strip("\r\n"))
    ]
    if ambiguous or len(transport_heads) > 1:
        raise ValueError("ambiguous transport YAML; refusing to change it")
    defaults = [
        "  nethernet:\n",
        "    key-file: nethernet.key\n",
        "    ice-udp-mux: true\n",
        f"    port-range: [{port}, {port}]\n",
    ]
    if not transport_heads:
        return source.rstrip("\n") + ("\n" if source else "") + (
            "\ntransport:\n  name: nethernet\n" + "".join(defaults)
        )

    start = transport_heads[0]
    end = next((i for i in range(start + 1, len(lines))
                if lines[i].strip() and
                not lines[i].startswith((" ", "\t", "#"))), len(lines))
    block = lines[start + 1:end]
    if any(re.match(r"^\s*\t", line) for line in block):
        raise ValueError("tabs in transport YAML are unsupported")

    name_lines = [i for i, line in enumerate(block) if re.match(r"^  name\s*:", line)]
    if len(name_lines) > 1:
        raise ValueError("duplicate transport.name entries")
    if name_lines:
        i = name_lines[0]
        block[i] = re.sub(r"^(  name\s*:).*?(\r?\n)?$",
                          r"\1 nethernet\n", block[i])
    else:
        block.insert(0, "  name: nethernet\n")

    nethernet_heads = [
        i for i, line in enumerate(block)
        if re.match(r"^  nethernet:\s*(?:#.*)?$", line.strip("\r\n"))
    ]
    if len(nethernet_heads) > 1:
        raise ValueError("duplicate transport.nethernet entries")
    if not nethernet_heads:
        block.extend(defaults)
    else:
        i = nethernet_heads[0]
        sub_end = next(
            (j for j in range(i + 1, len(block))
             if re.match(r"^  [A-Za-z_-]+\s*:", block[j])),
            len(block)
        )
        existing = block[i + 1:sub_end]
        needed = {
            "key-file": "nethernet.key",
            "ice-udp-mux": "true",
            "port-range": f"[{port}, {port}]",
        }
        additions = [
            f"    {key}: {value}\n" for key, value in needed.items()
            if not any(re.match(r"^    " + re.escape(key) + r"\s*:", line)
                       for line in existing)
        ]
        block[sub_end:sub_end] = additions

    return "".join(lines[:start + 1] + block + lines[end:])


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: configure_nethernet.py <pocketmine.yml> <tcp-and-udp-port>", file=sys.stderr)
        return 2
    path = Path(sys.argv[1])
    try:
        port = int(sys.argv[2])
        original = path.read_text(encoding="utf-8")
        updated = configure(original, port)
        if updated == original:
            print("[ReNether] NetherNet config already applied; identity key preserved")
            return 0
        backup = path.with_name(path.name + ".pre-26.60.bak")
        if not backup.exists():
            shutil.copy2(path, backup)
        fd, filename = tempfile.mkstemp(prefix=".pocketmine-", dir=path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as out:
                out.write(updated)
            os.chmod(filename, path.stat().st_mode & 0o777)
            os.replace(filename, path)
        finally:
            if os.path.exists(filename):
                os.unlink(filename)
        print(f"[ReNether] NetherNet enabled: TCP signaling {port}, UDP ICE default {port}")
        print(f"[ReNether] Original YAML backup: {backup}")
        return 0
    except (OSError, ValueError) as exc:
        print(f"[ReNether] ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
