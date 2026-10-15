<?php
declare(strict_types=1);

/**
 * CLI smoke test for the cPanel / DirectAdmin master UI.
 * Tests a realistic public_html + external private config layout without
 * contacting a remote API or relying on hosting-provider privileges.
 */
$home = sys_get_temp_dir() . '/hp-master-smoke-' . bin2hex(random_bytes(6));
$public = $home . '/public_html';
$private = $home . '/.helixpanel-master';
if (!mkdir($public, 0700, true) || !mkdir($private, 0700, true)) {
    fwrite(STDERR, "Cannot create test directories\n");
    exit(1);
}
$settings = [
    'upstream_api_base' => 'https://master-control.example.test',
    'upstream_api_token' => str_repeat('a', 48),
    'admin_password_hash' => password_hash('strict-test-password-unique', PASSWORD_DEFAULT),
];
file_put_contents($private . '/config.php', '<?php return ' . var_export($settings, true) . ';');
chmod($private . '/config.php', 0600);
$_SERVER['DOCUMENT_ROOT'] = $public;
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'master.example.test';
require dirname(__DIR__) . '/hosting-master/public/index.php';
