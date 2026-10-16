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
            for path in ("setup.php", "index.php", "license.php", "dashboard.html", "auto-update.php"):
                shutil.copy2(ROOT / "hosting-master" / "public" / path, public / path)
            for path in ("setup.css", "master.css", "logo-mark.webp", "app.js", "style.css", "dashboard-v2.css"):
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
                self.assertIn("'license_public_key' => 'UgMjnCA3zJnoH7Sn4CVZ-GucoqitVOdmrP6t03XlH9o'", content)
                self.assertNotIn("password-2026", finished)
                self.assertIn("ورود به مستر پنل", opener.open(urllib.request.Request(base + "setup.php", headers={"Cookie":session_header})).read().decode())
                # Verify the installed PHP dashboard actually serves the same
                # routed Go control center and uses an authenticated API bridge.
                login_page = opener.open(base).read().decode("utf-8")
                login_csrf = re.search(r'name="csrf" value="([a-f0-9]{64})"', login_page)
                self.assertIsNotNone(login_csrf)
                master_session = next((c for c in cookies if c.name == "hp_master"), None)
                self.assertIsNotNone(master_session)
                master_cookie = f"hp_master={master_session.value}"
                auth_request = urllib.request.Request(
                    base,
                    data=urllib.parse.urlencode({
                        "action":"login",
                        "password":"a-unique-admin-password-2026",
                        "csrf":login_csrf.group(1),
                    }).encode(),
                    headers={"Content-Type":"application/x-www-form-urlencoded","Cookie":master_cookie},
                    method="POST",
                )
                # The local HTTP test server emulates HTTPS; do not follow
                # the redirect because secure cookies are not sent over HTTP.
                class NoRedirect(urllib.request.HTTPRedirectHandler):
                    def redirect_request(self, request, fp, code, msg, headers, newurl):
                        return None
                login_client = urllib.request.build_opener(NoRedirect())
                with self.assertRaises(urllib.error.HTTPError) as login_redirect:
                    login_client.open(auth_request, timeout=10)
                self.assertEqual(login_redirect.exception.code, 303)
                from http.cookies import SimpleCookie
                rotated_cookie = SimpleCookie()
                rotated_cookie.load(login_redirect.exception.headers.get("Set-Cookie",""))
                self.assertIn("hp_master", rotated_cookie)
                master_cookie = "hp_master=" + rotated_cookie["hp_master"].value
                dashboard = opener.open(urllib.request.Request(
                    base, headers={"Cookie":master_cookie}),timeout=10).read().decode("utf-8")
                self.assertIn("data-helix-mode=\"hosted\"",dashboard)
                self.assertIn('data-view="nodes"',dashboard)
                self.assertIn('data-view="users"',dashboard)
                self.assertIn('assets/app.js?v=4',dashboard)
                bridge_csrf = re.search(r'name="helix-csrf" content="([a-f0-9]{64})"',dashboard)
                self.assertIsNotNone(bridge_csrf)
                api_req = urllib.request.Request(
                    base+"?hp_api="+urllib.parse.quote("nodes"),
                    headers={"Cookie":master_cookie},method="GET"
                )
                import json
                node_list = json.loads(opener.open(api_req,timeout=10).read().decode("utf-8"))
                self.assertIsInstance(node_list,list)
                no_csrf = urllib.request.Request(base+"?hp_api=nodes",
                    data=b'{"name":"test","region":"DE"}',method="POST",
                    headers={"Cookie":master_cookie,"Content-Type":"application/json"})
                with self.assertRaises(urllib.error.HTTPError) as csrf_failed:
                    opener.open(no_csrf,timeout=10)
                self.assertEqual(csrf_failed.exception.code,403)
                # A malicious request must not start a paid/privileged update
                # without both a valid login and the session's CSRF header.
                update_attempt=urllib.request.Request(
                    base+"?hp_api="+urllib.parse.quote("updates/install"),
                    data=b"",method="POST",headers={"Cookie":master_cookie})
                with self.assertRaises(urllib.error.HTTPError) as update_blocked:
                    opener.open(update_attempt,timeout=5)
                self.assertEqual(update_blocked.exception.code,403)
                # Exercise the same JSON create/delete operations as the Go UI.
                def mutate(method, resource, payload=None):
                    req = urllib.request.Request(
                        base+"?hp_api="+urllib.parse.quote(resource),
                        data=json.dumps(payload).encode() if payload is not None else None,
                        method=method,
                        headers={
                            "Cookie":master_cookie,
                            "X-Helix-CSRF":bridge_csrf.group(1),
                            "Content-Type":"application/json",
                        },
                    )
                    try:
                        return json.loads(opener.open(req,timeout=10).read().decode("utf-8"))
                    except urllib.error.HTTPError as exc:
                        detail = exc.read(300).decode("utf-8",errors="replace")
                        self.fail(f"API {method} {resource}: HTTP {exc.code}: {detail}")
                # The Community edition can be pre-provisioned at its
                # licensed limit. Respect license enforcement in that case.
                def read(resource):
                    request=urllib.request.Request(
                        base+"?hp_api="+urllib.parse.quote(resource),
                        headers={"Cookie":master_cookie},method="GET")
                    return json.loads(opener.open(request,timeout=10).read().decode("utf-8"))
                license_status=read("license")
                if len(node_list) < license_status["max_nodes"]:
                    created_node=mutate("POST","nodes",{"name":"ci-fra-node","region":"DE"})
                    self.assertRegex(created_node["id"],r"^[a-f0-9]{32}$")
                    mutate("DELETE","nodes/"+created_node["id"])
                else:
                    self.assertGreaterEqual(len(node_list),license_status["max_nodes"])
                from datetime import datetime, timezone, timedelta
                expiry=(datetime.now(timezone.utc)+timedelta(days=31)).strftime("%Y-%m-%dT23:59:59Z")
                if len(read("users")) < license_status["max_users"]:
                    created_user=mutate("POST","users",{"name":"ci-user-001","quota_gb":10,"expires_at":expiry})
                    self.assertRegex(created_user["id"],r"^[a-f0-9]{32}$")
                    patched=mutate("PATCH","users/"+created_user["id"],
                        {"quota_gb":18,"expires_at":expiry})
                    self.assertEqual(int(patched["quota_gb"]),18)
                    self.assertEqual(patched["status"],"pending_provisioning")
                    with self.assertRaises(AssertionError):
                        mutate("PATCH","users/"+created_user["id"],
                            {"quota_gb":0,"expires_at":expiry})
                    self.assertEqual(int(next(u for u in read("users") if u["id"]==created_user["id"])["quota_gb"]),18)
                    mutate("DELETE","users/"+created_user["id"])
                unauthenticated = urllib.request.Request(base+"?hp_api=nodes",method="GET")
                with self.assertRaises(urllib.error.HTTPError) as denied:
                    opener.open(unauthenticated,timeout=10)
                self.assertEqual(denied.exception.code,401)
                probe = opener.open(base + "license.php").read().decode("utf-8")
                import json
                installation = json.loads(probe)
                self.assertRegex(installation["installation_id"], r"^[a-f0-9]{32}$")
                self.assertEqual(installation["product"], "helixpanel")
                # Official public verification key is preconfigured, but
                # arbitrary unsigned tokens must still be rejected.
                req = urllib.request.Request(
                    base + "license.php",
                    data=b'{"license_key":"untrusted.token"}',
                    method="POST",headers={"Content-Type":"application/json"},
                )
                with self.assertRaises(urllib.error.HTTPError) as failure:
                    opener.open(req,timeout=5)
                self.assertEqual(failure.exception.code,403)

            finally:
                server.terminate()
                try:
                    server.communicate(timeout=6)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.communicate(timeout=3)


if __name__ == "__main__":
    unittest.main()
