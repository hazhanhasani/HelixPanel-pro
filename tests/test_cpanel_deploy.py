"""Offline contract tests for the cPanel bootstrap helper. Never call a real host."""
import importlib.util
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("helix_deploy",ROOT/"scripts/deploy_cpanel.py")
deploy=importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

class CPanelDeployTests(unittest.TestCase):
    def test_valid_explicit_configuration(self):
        config={"CPANEL_HOST":"host.example.test","CPANEL_USERNAME":"testuser",
                "CPANEL_API_TOKEN":"offline_dummy_api_token_0123456789",
                "CPANEL_PHP_BIN":"/usr/local/bin/php"}
        self.assertEqual(deploy.settings(config),
                         ("host.example.test","testuser","offline_dummy_api_token_0123456789","/usr/local/bin/php"))

    def test_invalid_host_and_user_rejected(self):
        good={"CPANEL_HOST":"host.example.test","CPANEL_USERNAME":"testuser",
              "CPANEL_API_TOKEN":"offline_dummy_api_token_0123456789"}
        for bad in ("host/../../", "127.0.0.1:8080", "evil@example.test", "--bad.example.test"):
            with self.subTest(host=bad), self.assertRaises(deploy.DeployError):
                deploy.settings({**good,"CPANEL_HOST":bad})
        for bad in ("root", "../root", "user;whoami"):
            # Usernames must be account names, not root or shell expressions.
            if bad=="root":continue
            with self.subTest(user=bad), self.assertRaises(deploy.DeployError):
                deploy.settings({**good,"CPANEL_USERNAME":bad})

    def test_uploads_only_expected_updater(self):
        body,content_type=deploy.multipart_file("auto-update.php",b"<?php echo 'offline';")
        self.assertIn("multipart/form-data",content_type)
        self.assertIn(b"auto-update.php",body)
        self.assertIn(b"public_html",body)
        with self.assertRaises(deploy.DeployError):
            deploy.multipart_file("config.php",b"wrong")
        with self.assertRaises(deploy.DeployError):
            deploy.multipart_file("auto-update.php",b"x"*(1024*1024+1))

    def test_redirects_cannot_forward_authentication(self):
        self.assertIsNone(deploy.NoRedirect().redirect_request(None,None,302,"Redirect",{},"https://elsewhere.test"))

if __name__=="__main__":
    unittest.main()
