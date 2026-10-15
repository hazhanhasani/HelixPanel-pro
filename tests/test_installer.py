import base64
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import installer

class InstallerSecurityTests(unittest.TestCase):
    def test_identity_stable_and_refuses_corruption(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            identity = installer.read_identity(p)
            self.assertEqual(identity, installer.read_identity(p))
            self.assertEqual(len(identity), 32)
            (p / "installation.id").write_text("bad\n")
            with self.assertRaises(installer.InstallError):
                installer.read_identity(p)

    def test_signed_release_manifest_and_tampering(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            private = p / "private.pem"
            public = p / "public.pem"
            data = p / "payload"
            signature = p / "signature"
            subprocess.run(["openssl", "genpkey", "-algorithm", "ED25519", "-out", str(private)], check=True)
            subprocess.run(["openssl", "pkey", "-in", str(private), "-pubout", "-out", str(public)], check=True)
            raw = json.dumps({"product": "helixpanel", "version": "v0.1.0",
                              "sha256": "f" * 64, "expires_at": 1999999999}).encode()
            data.write_bytes(raw)
            subprocess.run(["openssl", "pkeyutl", "-sign", "-inkey", str(private), "-rawin",
                            "-in", str(data), "-out", str(signature)], check=True)
            encode = lambda b: base64.urlsafe_b64encode(b).rstrip(b"=").decode()
            env = {"payload": encode(raw), "signature": encode(signature.read_bytes())}
            decoded = installer.verify_release_envelope(env, public.read_text(), now=1750000000)
            self.assertEqual(decoded["version"], "v0.1.0")
            env["payload"] = encode(raw.replace(b"v0.1.0", b"v0.9.9"))
            with self.assertRaises(installer.InstallError):
                installer.verify_release_envelope(env, public.read_text(), now=1750000000)

    def test_safe_tar_extract_and_reject_symlink(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            good = p / "good.tar.gz"
            with tarfile.open(good, "w:gz") as tf:
                b = b"binary"
                info = tarfile.TarInfo("helixpanel")
                info.size = len(b)
                tf.addfile(info, io.BytesIO(b))
            output = p / "out"
            installer.unpack_binary(good, output)
            self.assertEqual(output.read_bytes(), b"binary")
            bad = p / "bad.tar.gz"
            with tarfile.open(bad, "w:gz") as tf:
                info = tarfile.TarInfo("helixpanel")
                info.type = tarfile.SYMTYPE
                info.linkname = "/etc/passwd"
                tf.addfile(info)
            with self.assertRaises(installer.InstallError):
                installer.unpack_binary(bad, output)

    def test_rejects_http_api(self):
        with tempfile.TemporaryDirectory() as d:
            config = Path(d) / "config.json"
            config.write_text(json.dumps({"api_base": "http://insecure.example", "release_public_key_pem": "x", "license_public_key": "y"}))
            with self.assertRaises(installer.InstallError):
                installer.read_config(config)

if __name__ == "__main__":
    unittest.main()
