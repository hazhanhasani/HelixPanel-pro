"""Static compatibility checks for the real hosted PHP dashboard V2."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "hosting-master/public/index.php"
ASSETS = ROOT / "hosting-master/public/assets"
WORKFLOW = ROOT / ".github/workflows/hosting-master-package.yml"


class DashboardV2Tests(unittest.TestCase):
    def test_assets_exist_and_are_referenced_by_authenticated_dashboard(self):
        text = PHP.read_text(encoding="utf-8")
        for name in ("dashboard-v2.css", "dashboard-v2.js"):
            self.assertTrue((ASSETS / name).is_file())
            self.assertIn("assets/" + name, text)
            self.assertIn("public / \"assets/" + name + "\"", WORKFLOW.read_text(encoding="utf-8"))

    def test_dashboard_keeps_secure_session_and_csrf(self):
        php = PHP.read_text(encoding="utf-8")
        self.assertIn("session_regenerate_id(true)", php)
        self.assertIn("hash_equals", php)
        self.assertIn("CURLOPT_SSL_VERIFYPEER => true", php)
        self.assertIn("htmlspecialchars", php)
        self.assertIn("script-src 'self'", php)
        self.assertIn("data-confirm=", php)

    def test_no_fabricated_telemetry_or_exposure_of_private_api_token(self):
        php = PHP.read_text(encoding="utf-8")
        self.assertIn("داده مصرف زنده هنوز وجود ندارد", php)
        self.assertIn("nodeOnline", php)
        self.assertIn("licenseMaxNodes", php)
        self.assertNotIn("8.6 TB", php)
        self.assertNotIn("<?= h($apiToken)", php)
        self.assertNotIn("<?= $apiToken", php)

    def test_mobile_search_and_jalali_are_progressive(self):
        js = (ASSETS / "dashboard-v2.js").read_text(encoding="utf-8")
        for term in ("mobile-menu-toggle", "nodes-table", "users-table", "fa-IR-u-ca-persian", "data-confirm"):
            self.assertIn(term, js)
        self.assertNotIn("innerHTML", js)
        self.assertNotIn("localStorage", js)

if __name__ == "__main__":
    unittest.main()
