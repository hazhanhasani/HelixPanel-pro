"""End-to-end first-run hosting setup through a temporary local PHP web server.

Runs with a dedicated ephemeral document root, PHP sessions and CI MySQL.
Never serves a real customer's credentials or requires root.
"""
import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class QuickHostingSetupTest(unittest.TestCase):
    def test_secure_browser_setup_and_community_license(self):
        for name in ("HP_TEST_DSN", "HP_TEST_USER", "HP_TEST_PASSWORD"):
            if not os.environ.get(name):
                self.skipTest("live MySQL environment unavailable")
        dsn = os.environ["HP_TEST_DSN"]
        db_name = re.search(r"dbname=([A-Za-z0-9_]+)", dsn)
        self.assertIsNotNone(db_name)
        mysql_host = re.search(r"host=([A-Za-z0-9.\-]+)", dsn)
        mysql_port = re.search(r"port=(\d+)", dsn)
        with tempfile.TemporaryDirectory(prefix="hp-setup-ci-") as tmp:
            directory = Path(tmp)
            public = directory / "public_html"
            public.mkdir()
            (public / "assets").mkdir()
            for path in ("setup.php", "index.php"):
                shutil.copy2(ROOT / "hosting-master" / "public" / path, public / path)
            for path in ("setup.css", "master.css", "logo-mark.webp"):
                shutil.copy2(ROOT / "hosting-master/public/assets" / path, public / "assets" / path)
            shutil.copy2(ROOT / "hosting-master/private/store.php", public / "setup-store.php")
            prepend = directory / "https.php"
            prepend.write_text("<?php $_SERVER['HTTPS']='on'; $_SERVER['SERVER_PORT']=443;")
            with socket.socket() as sock:
                sock.bind(("127.0.0.1", 0))
                port = sock.getsockname()[1]
            cmd = ["php", "-d", f"auto_prepend_file={prepend}", "-S",
                   f"127.0.0.1:{port}", "-t", str(public)]
            env = dict(os.environ)
            env["PHP_CLI_SERVER_WORKERS"] = "1"
            server = subprocess.Popen(cmd, stdout=subprocess.DEVNULL,
                                      stderr=subprocess.PIPE, env=env)
            try:
                base = f"http://127.0.0.1:{port}/"
                cookies = http.cookiejar.CookieJar()
                opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
                for _ in range(30):
                    try:
                        response = opener.open(base + "setup.php", timeout=2)
                        break
                    except (OSError, urllib.error.HTTPError):
                        if server.poll() is not None:
                            self.fail("PHP server terminated")
                        time.sleep(.1)
                else:
                    self.fail("PHP server never became available")
                html = response.read().decode("utf-8")
                # Secure cookies intentionally never travel over HTTP. This
                # local-only CI server emulates HTTPS via PHP variables, so
                # explicitly attach the session cookie to mock the TLS browser.
                session = next((c for c in cookies if c.name == "hp_setup"), None)
                self.assertIsNotNone(session)
                session_header = f"hp_setup={session.value}"
                self.assertIn("راه‌اندازی مستر پنل", html)
                csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', html)
                self.assertIsNotNone(csrf)
                private = directory / ".helixpanel-master"
                secret_file = private / "setup.key"
                self.assertTrue(secret_file.is_file())
                secret = secret_file.read_text().strip()
                self.assertRegex(secret, r"^[a-f0-9]{64}$")
                self.assertNotIn(secret, html)
                self.assertFalse((public / "config.php").exists())
                form = {
                    "csrf": csrf.group(1), "setup_key": "0" * 64,
                    "db_host": (mysql_host.group(1) if mysql_host else "127.0.0.1"),
                    "db_port": (mysql_port.group(1) if mysql_port else "3306"),
                    "db_name": db_name.group(1), "db_user": os.environ["HP_TEST_USER"],
                    "db_password": os.environ["HP_TEST_PASSWORD"],
                    "admin_password": "a-unique-admin-password-2026",
                    "confirm_password": "a-unique-admin-password-2026",
                }
                def submit(payload):
                    req = urllib.request.Request(
                        base + "setup.php",
                        data=urllib.parse.urlencode(payload).encode(),
                        headers={"Content-Type": "application/x-www-form-urlencoded", "Cookie": session_header},
                        method="POST",
                    )
                    return opener.open(req, timeout=10).read().decode("utf-8")
                invalid = submit(form)
                self.assertIn("کد راه‌اندازی اشتباه است", invalid)
                self.assertFalse((private / "config.php").exists())
                form["setup_key"] = secret
                finished = submit(form)
                if "ورود به مستر پنل" not in finished:
                    self.fail("Master login did not load after setup: " + finished[:400])
                self.assertTrue((private / "config.php").exists())
                self.assertTrue((private / "store.php").exists())
                self.assertFalse(secret_file.exists())
                self.assertFalse((public / "setup-store.php").exists())
                content = (private / "config.php").read_text()
                self.assertIn("'mode' => 'standalone'", content)
                self.assertIn("'license_key' => ''", content)
                self.assertNotIn("password-2026", finished)
                self.assertIn("ورود به مستر پنل", opener.open(urllib.request.Request(base + "setup.php", headers={"Cookie":session_header})).read().decode())
            finally:
                server.terminate()
                try:
                    server.communicate(timeout=6)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.communicate(timeout=3)


if __name__ == "__main__":
    unittest.main()
