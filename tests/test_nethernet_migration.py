"""Regression tests for non-destructive Bedrock 26.60 YAML migration."""
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from configure_nethernet import configure


class NetherNetMigrationTests(unittest.TestCase):
    def test_fresh_config_preserves_other_settings(self):
        original = "settings:\n  language: eng\n"
        result = configure(original, 19133)
        self.assertIn("  language: eng", result)
        self.assertIn("transport:\n  name: nethernet", result)
        self.assertIn("port-range: [19133, 19133]", result)
        self.assertIn("ice-udp-mux: true", result)

    def test_existing_transport_preserves_custom_key_and_other_settings(self):
        original = (
            "transport:\n"
            "  name: raknet\n"
            "  nethernet:\n"
            "    key-file: my-identity.key\n"
            "    mtu: 1100\n"
            "  another-option: 42\n"
            "server:\n  player-limit: 100\n"
        )
        result = configure(original, 19133)
        self.assertIn("name: nethernet", result)
        self.assertIn("key-file: my-identity.key", result)
        self.assertIn("mtu: 1100", result)
        self.assertIn("another-option: 42", result)
        self.assertIn("server:\n  player-limit: 100", result)
        self.assertNotIn("key-file: nethernet.key", result)

    def test_idempotent(self):
        original = "transport:\n  name: raknet\n"
        first = configure(original, 19133)
        self.assertEqual(first, configure(first, 19133))

    def test_custom_ports_not_overwritten(self):
        original = (
            "transport:\n"
            "  name: nethernet\n"
            "  nethernet:\n"
            "    ice-udp-mux: true\n"
            "    port-range: [19200, 19200]\n"
        )
        self.assertIn("port-range: [19200, 19200]", configure(original, 19133))

    def test_reject_ambiguous_transport(self):
        with self.assertRaises(ValueError):
            configure("transport: {name: raknet}\n", 19133)

    def test_reject_bad_port(self):
        with self.assertRaises(ValueError):
            configure("", 65536)


if __name__ == "__main__":
    unittest.main()
