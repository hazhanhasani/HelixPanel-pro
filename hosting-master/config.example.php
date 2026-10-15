<?php
declare(strict_types=1);
/*
 * Copy to: dirname(realpath(DOCUMENT_ROOT))/.helixpanel-master/config.php
 * PUBLIC DOCROOT MUST END IN /public_html.
 * Install store.php next to this file from hosting-master/private/store.php.
 * No source of the proprietary Go VPN control plane is needed.
 */
return [
    'mode'=>'standalone',
    'db_dsn'=>'mysql:host=localhost;port=3306;dbname=HELIX_DB;charset=utf8mb4',
    'db_user'=>'HELIX_DB_USER',
    'db_password'=>'REPLACE_WITH_DATABASE_PASSWORD',
    'admin_password_hash'=>'REPLACE_WITH_PASSWORD_HASH',
    // Vendor-issued signed license; leave empty for 1-node / 25-user preview.
    'license_key'=>'',
    'license_public_key'=>'',
    // Set only if deliberately using legacy gateway-to-Go mode.
    // 'mode'=>'gateway',
    // 'upstream_api_base'=>'https://your-go-control-plane.example',
    // 'upstream_api_token'=>'32_CHARACTERS_OR_MORE',
];
