"""Regression tests for unattended license activation and automatic updates."""
import base64
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import installer

ROOT = Path(__file__).resolve().parents[1]


class AutoLicensingTests(unittest.TestCase):
    @staticmethod
    def b64(raw):
        return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()

    def test_real_ed25519_license_and_rejects_tampering(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            priv = root / "private.pem"
            der = root / "public.der"
            payload_file = root / "payload"
            sig_file = root / "signature"
            subprocess.run(["openssl", "genpkey", "-algorithm", "ED25519", "-out", str(priv)], check=True)
            subprocess.run(["openssl", "pkey", "-in", str(priv), "-pubout",
                            "-outform", "DER", "-out", str(der)], check=True)
            pub = self.b64(der.read_bytes()[-32:])
            identity = "1" * 32
            now = int(time.time())
            claims = {"version": 1, "product": "helixpanel", "license_id": "lic-1",
                      "customer_id": "cus-1", "installation_id": identity,
                      "issued_at": now, "expires_at": now + 3600,
                      "max_users": 500, "max_nodes": 5}
            raw = json.dumps(claims).encode()
            payload_file.write_bytes(raw)
            subprocess.run(["openssl", "pkeyutl", "-sign", "-inkey", str(priv), "-rawin",
                            "-in", str(payload_file), "-out", str(sig_file)], check=True)
            token = self.b64(raw) + "." + self.b64(sig_file.read_bytes())
            self.assertEqual(installer.verify_license_token(token, pub, identity)["max_users"], 500)
            with self.assertRaises(installer.InstallError):
                installer.verify_license_token(token, pub, "2" * 32)
            with self.assertRaises(installer.InstallError):
                installer.verify_license_token(self.b64(raw.replace(b"500", b"900")) + "." +
                                               self.b64(sig_file.read_bytes()), pub, identity)
            with self.assertRaises(installer.InstallError):
                installer.verify_license_token(token, pub, identity, now=now + 7200)

    def test_rejects_malformed_activation_codes_before_network(self):
        with patch("installer.urllib.request.urlopen") as requester:
            with self.assertRaises(installer.InstallError):
                installer.request_activation("https://example.org", "guess", "a" * 32)
            requester.assert_not_called()

    def test_shell_syntax_and_timer_configuration(self):
        for name in ("install.sh", "auto-update.sh"):
            subprocess.run(["bash", "-n", str(ROOT / name)], check=True)
        setup = (ROOT / "install.sh").read_text(encoding="utf-8")
        self.assertIn("OnUnitActiveSec=6h", setup)
        self.assertIn("RandomizedDelaySec=20min", setup)
        self.assertIn("helixpanel-update.timer", setup)
        self.assertIn('--activation-code-file "$activation_source"', setup)
        self.assertIn("systemctl enable --now helixpanel-update.timer", setup)
        self.assertNotIn("--activation-code-file \\\\n", setup)
        self.assertIn("out.write(license_key +", (ROOT / "installer.py").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
