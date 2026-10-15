<?php
declare(strict_types=1);
/**
 * Copy to:
 *   cPanel: /home/CPANEL_USER/.helixpanel-master/config.php
 *   DirectAdmin: /home/DA_USER/domains/DOMAIN.COM/.helixpanel-master/config.php
 * The actual path is dirname(realpath(DOCUMENT_ROOT))/.helixpanel-master/config.php
 * when the document root ends in /public_html.
 *
 * This file MUST be outside every publicly served document root.
 * Permissions: directory 0700; config.php 0600 (or hosting-account equivalent).
 *
 * Password hash generation on your local machine:
 * php -r 'echo password_hash("REPLACE_WITH_A_LONG_UNIQUE_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
 */
return [
    'upstream_api_base' => 'https://YOUR-PRIVATE-CONTROL-PLANE.example',
    'upstream_api_token' => 'REPLACE_WITH_STRONG_SCOPED_TOKEN_AT_LEAST_32_CHARACTERS',
    'admin_password_hash' => '$2y$12$REPLACE_WITH_REAL_PHP_PASSWORD_HASH',
];
