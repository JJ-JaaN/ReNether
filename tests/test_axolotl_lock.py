"""Verify that OAuth-independent BedrockProtocol archive stays pinned."""
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from prepare_axolotl_lock import adapt, SHA, VERSION, API_URL, PUBLIC_URL


def lock(version=VERSION, sha=SHA, url=API_URL):
    return json.dumps({
        "content-hash": "unchanged",
        "packages": [
            {
                "name": "axolotl-pm/bedrock-protocol",
                "version": version,
                "source": {"type": "git", "reference": sha},
                "dist": {"type": "zip", "url": url, "reference": sha, "shasum": ""},
            },
            {
                "name": "example/unchanged",
                "version": "1.2.3",
                "source": {"type": "git", "reference": "other"},
                "dist": {"type": "zip", "reference": "other", "url": "https://example.org/foo.zip"},
            },
        ],
    }, indent=4)


class PublicDistTests(unittest.TestCase):
    def test_exact_commit_not_upgraded(self):
        src = lock()
        result = adapt(src)
        before = json.loads(src)
        after = json.loads(result)
        self.assertEqual(after["packages"][0]["dist"]["url"], PUBLIC_URL)
        self.assertEqual(after["packages"][0]["source"], before["packages"][0]["source"])
        self.assertEqual(after["packages"][0]["version"], VERSION)
        self.assertEqual(after["packages"][1], before["packages"][1])
        self.assertEqual(after["content-hash"], before["content-hash"])

    def test_idempotent(self):
        result = adapt(lock())
        self.assertEqual(adapt(result), result)

    def test_unknown_commit_rejected(self):
        with self.assertRaises(ValueError):
            adapt(lock(sha="0" * 40))

    def test_wrong_version_rejected(self):
        with self.assertRaises(ValueError):
            adapt(lock(version="65.0.0"))

    def test_unexpected_archive_url_rejected(self):
        with self.assertRaises(ValueError):
            adapt(lock(url="https://example.org/unknown.zip"))

    def test_missing_package_rejected(self):
        with self.assertRaises(ValueError):
            adapt('{"packages": []}')


if __name__ == "__main__":
    unittest.main()
