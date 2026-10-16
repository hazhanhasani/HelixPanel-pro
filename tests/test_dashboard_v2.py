"""Regression checks for one shared, routed Go/PHP dashboard."""
from pathlib import Path
import re
import json
import hashlib
import unittest

ROOT=Path(__file__).resolve().parents[1]
PUBLIC=ROOT/"hosting-master/public"
PHP=(PUBLIC/"index.php").read_text(encoding="utf-8")
DASH=(PUBLIC/"dashboard.html").read_text(encoding="utf-8")
JS=(PUBLIC/"assets/app.js").read_text(encoding="utf-8")
PACKAGE=(ROOT/".github/workflows/hosting-master-package.yml").read_text(encoding="utf-8")
UPDATER=(PUBLIC/"auto-update.php").read_text(encoding="utf-8")

class SharedDashboardTests(unittest.TestCase):
    def test_shared_template_has_real_mutually_exclusive_views(self):
        self.assertEqual(len(re.findall(r'<section id="(overview|nodes|users|license)" class="view', DASH)),4)
        for view in ["overview","nodes","users","license"]:
            self.assertIn(f'data-view="{view}"',DASH)
        self.assertIn("item.hidden=!active",JS)
        self.assertIn("window.history.pushState",JS)
        self.assertIn("window.addEventListener('popstate'",JS)
        self.assertNotIn('href="#nodes"',DASH)
        self.assertNotIn('href="#users"',DASH)

    def test_exact_same_js_css_html_for_both_backends(self):
        self.assertIn("file_get_contents($dashboardFile)",PHP)
        self.assertIn('data-helix-mode="hosted"',PHP)
        self.assertIn("meta[name=", JS)
        for file in ["dashboard.html","assets/app.js","assets/style.css","assets/dashboard-v2.css","assets/logo-mark.webp"]:
            self.assertTrue((PUBLIC/file).is_file(),file)
            self.assertIn('public / "'+file+'"', PACKAGE)
        self.assertIn("'assets/app.js'",UPDATER)
        self.assertIn("'dashboard.html'",UPDATER)

    def test_shared_sources_are_byte_identical_to_core_manifest(self):
        manifest=json.loads((ROOT/"hosting-master/shared-ui.manifest.json").read_text(encoding="utf-8"))
        for relative, expected in manifest["assets"].items():
            raw=(ROOT/relative).read_bytes()
            actual=hashlib.sha1(b"blob "+str(len(raw)).encode()+b"\0"+raw).hexdigest()
            self.assertEqual(actual,expected,relative)

    def test_cpanel_authenticated_api_does_not_expose_secrets(self):
        self.assertIn("hash_equals",PHP)
        self.assertIn("HTTP_X_HELIX_CSRF",PHP)
        self.assertIn("session_regenerate_id(true)",PHP)
        self.assertIn("htmlspecialchars",PHP)
        self.assertIn("CURLOPT_SSL_VERIFYPEER => true",PHP)
        self.assertIn("script-src 'self'",PHP)
        self.assertNotIn("<?= $apiToken",PHP)
        self.assertIn("credentials:'same-origin'",JS)
        self.assertNotIn("localStorage",JS)
        self.assertNotIn("innerHTML",JS)

    def test_backend_adapter_and_data_are_not_simulated(self):
        self.assertIn("hp_api",PHP)
        self.assertIn("is_array($payload)",PHP)
        self.assertIn("pending_enrollment",JS)
        self.assertIn("onlineNodes=state.nodes.filter",JS)
        self.assertNotIn("8.6 TB",DASH)

if __name__=="__main__":
    unittest.main()
