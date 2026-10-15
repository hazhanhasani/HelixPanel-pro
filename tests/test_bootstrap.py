"""Offline smoke tests for public one-command bootstrap (no root/network calls)."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PublicBootstrapTests(unittest.TestCase):
    def test_bash_syntax(self):
        subprocess.run(["bash", "-n", str(ROOT / "install.sh")], check=True)

    def test_help_is_safe_offline(self):
        result = subprocess.run(
            ["bash", str(ROOT / "install.sh"), "--help"],
            check=True, text=True, capture_output=True, timeout=5,
        )
        self.assertIn("install.sh | sudo bash", result.stdout)
        self.assertIn("HelixPanel Pro", result.stdout)

    def test_invalid_command_is_rejected_without_side_effects(self):
        result = subprocess.run(
            ["bash", str(ROOT / "install.sh"), "arbitrary"],
            text=True, capture_output=True, timeout=5,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Unknown command", result.stderr)

    def test_release_config_is_explicitly_preview(self):
        config = json.loads((ROOT / "config.public.json").read_text(encoding="utf-8"))
        self.assertIn(config["status"], ("preview", "production"))
        if config["status"] == "preview":
            self.assertEqual(config["api_base"], "")
            self.assertEqual(config["release_public_key_pem"], "")
            self.assertEqual(config["license_public_key"], "")

    def test_one_command_is_documented(self):
        readme = (ROOT / "README.md").read_text(encoding="utf-8")
        self.assertIn(
            "https://raw.githubusercontent.com/hazhanhasani/HelixPanel-pro/main/install.sh | sudo bash",
            readme,
        )


if __name__ == "__main__":
    unittest.main()
