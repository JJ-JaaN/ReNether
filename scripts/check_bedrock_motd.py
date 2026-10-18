#!/usr/bin/env python3
"""Ask local UDP RakNet listeners which Minecraft Bedrock name they advertise."""
from __future__ import annotations

import socket
import struct
import sys
import time

MAGIC = bytes.fromhex("00ffff00fefefefefdfdfdfd12345678")
PING = (
    b"\x01"
    + struct.pack(">Q", int(time.time() * 1000))
    + MAGIC
    + struct.pack(">Q", 0x72654E6574686572)
)


def check(port: int) -> None:
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.settimeout(2)
            sock.sendto(PING, ("127.0.0.1", port))
            data, _ = sock.recvfrom(4096)
    except OSError as error:
        print(f"UDP {port}: no Bedrock ping response ({error})")
        return

    if len(data) < 35 or data[0] != 0x1C or data[17:33] != MAGIC:
        print(f"UDP {port}: unexpected reply ({len(data)} bytes, id=0x{data[0]:02x})")
        return
    length = struct.unpack_from(">H", data, 33)[0]
    if len(data) < 35 + length:
        print(f"UDP {port}: truncated Bedrock ping response")
        return
    fields = data[35:35 + length].decode("utf-8", errors="replace").split(";")
    name = fields[1] if len(fields) > 1 else "<unknown>"
    players = fields[4] if len(fields) > 4 else "?"
    print(f"UDP {port}: ADVERTISED={name!r}, players={players}")
    print(f"          Raw pong: {';'.join(fields[:8])}")


def main() -> None:
    ports = [int(v) for v in sys.argv[1:]] if len(sys.argv) > 1 else [19132, 19133]
    for port in ports:
        if not 1 <= port <= 65535:
            raise SystemExit(f"Invalid UDP port: {port}")
        check(port)


if __name__ == "__main__":
    main()
