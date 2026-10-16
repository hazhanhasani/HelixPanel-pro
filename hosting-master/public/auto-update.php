<?php
declare(strict_types=1);

/**
 * HelixPanel hosted-dashboard updater.
 *
 * Downloads only allowlisted public UI files from an immutable, GitHub-hosted
 * commit after the official packaging workflow has passed for that SHA.
 * GitHub is the trusted update publisher. No GitHub credentials are required.
 * Existing DB, config, store, license and node runtime files are never touched.
 *
 * Called after an authenticated dashboard request, or from a local CLI cron.
 * Direct HTTP access is intentionally denied.
 */
if (!defined('HELIX_UPDATE_INTERNAL') && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const HP_UPDATER_REPO = 'hazhanhasani/HelixPanel-pro';
const HP_UPDATER_WORKFLOW = 'hosting-master-package.yml';
const HP_UPDATER_FILES = [
    'index.php',
    'dashboard.html',
    'auto-update.php',
    'assets/app.js',
    'assets/style.css',
    'assets/master.css',
    'assets/dashboard-v2.css',
    'assets/dashboard-v2.js',
    'assets/logo-mark.webp',
];

/** HTTPS only, strict TLS, bounded responses, no redirect following. */
function hpUpdateGet(string $url, int $limit = 262144): string {
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    if ((parse_url($url, PHP_URL_SCHEME) !== 'https')
        || !in_array($host, ['api.github.com', 'raw.githubusercontent.com'], true)) {
        throw new RuntimeException('Untrusted update host');
    }
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Update transport unavailable');
    $body = '';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'HelixPanelHostedUpdater/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$body, $limit): int {
            if (strlen($body) + strlen($data) > $limit) return 0;
            $body .= $data;
            return strlen($data);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
    } else {
        curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($ok === false || $status !== 200) throw new RuntimeException('Update server unavailable');
    return $body;
}

function hpUpdateJson(string $body): array {
    $value = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('Invalid update response');
    return $value;
}

function hpUpdateBlobHash(string $body): string {
    return hash('sha1', 'blob ' . strlen($body) . "\0" . $body);
}

function hpUpdateSafePath(string $root, string $relative): string {
    if (!in_array($relative, HP_UPDATER_FILES, true)) {
        throw new RuntimeException('Update file not allowed');
    }
    $base = realpath($root);
    if ($base === false || basename($base) !== 'public_html' || is_link($base)) {
        throw new RuntimeException('Unexpected hosting document root');
    }
    $dir = dirname($relative) === '.' ? $base : $base . '/assets';
    $resolved = realpath($dir);
    if ($resolved !== $dir || is_link($dir)) {
        throw new RuntimeException('Unsafe asset directory');
    }
    $target = $dir . '/' . basename($relative);
    if (is_link($target) || (file_exists($target) && !is_file($target))) {
        throw new RuntimeException('Unsafe existing update target');
    }
    return $target;
}

/**
 * Performs an atomic-per-file update with pre-downloaded Git blob verification,
 * and restores files from a private backup if any rename fails.
 */
function hpUpdateNow(string $root, string $private, ?callable $fetch = null): string {
    $fetch ??= 'hpUpdateGet';
    $repo = HP_UPDATER_REPO;
    // Select the latest approved package, not the latest commit on main.
    // Docs-only commits do not trigger packaging and must never block upgrades.
    $run = hpUpdateJson($fetch(
        "https://api.github.com/repos/$repo/actions/workflows/" . HP_UPDATER_WORKFLOW .
        "/runs?branch=main&per_page=25", 196608
    ));
    $sha = null;
    foreach (($run['workflow_runs'] ?? []) as $entry) {
        $candidate = $entry['head_sha'] ?? null;
        if (($entry['conclusion'] ?? '') === 'success'
            && ($entry['event'] ?? '') === 'push'
            && ($entry['head_branch'] ?? '') === 'main'
            && is_string($candidate)
            && preg_match('/^[0-9a-f]{40}$/D', $candidate)) {
            $sha = $candidate;
            break;
        }
    }
    if ($sha === null) return 'awaiting_ci';

    $currentPath = $private . '/dashboard-revision';
    if (is_file($currentPath) && trim((string)file_get_contents($currentPath)) === $sha) {
        return 'up_to_date';
    }

    $tree = hpUpdateJson($fetch("https://api.github.com/repos/$repo/git/trees/$sha?recursive=1", 262144));
    if (!empty($tree['truncated'])) throw new RuntimeException('Incomplete update tree');
    $hashes = [];
    foreach (($tree['tree'] ?? []) as $item) {
        if (($item['type'] ?? '') === 'blob' && is_string($item['path'] ?? null)) {
            $hashes[$item['path']] = $item['sha'] ?? '';
        }
    }
    $downloads = [];
    foreach (HP_UPDATER_FILES as $relative) {
        $repoPath = 'hosting-master/public/' . $relative;
        $expected = $hashes[$repoPath] ?? null;
        if (!is_string($expected) || !preg_match('/^[0-9a-f]{40}$/D', $expected)) {
            throw new RuntimeException('Incomplete release files');
        }
        $body = $fetch("https://raw.githubusercontent.com/$repo/$sha/$repoPath", 524288);
        if (!is_string($body) || hpUpdateBlobHash($body) !== $expected) {
            throw new RuntimeException('Release file digest mismatch');
        }
        $downloads[$relative] = $body;
    }
    // Stage all files in their destination directories, then perform replacements.
    $staged = [];
    $backupRoot = $private . '/update-backups';
    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
        throw new RuntimeException('Cannot create protected update backup directory');
    }
    $backup = $backupRoot . '/' . $sha;
    if (!is_dir($backup) && !mkdir($backup, 0700)) {
        throw new RuntimeException('Cannot create release backup');
    }
    $changed = [];
    try {
        foreach ($downloads as $relative => $body) {
            $target = hpUpdateSafePath($root, $relative);
            if (is_file($target) && hpUpdateBlobHash((string)file_get_contents($target)) === hpUpdateBlobHash($body)) continue;
            $name = $backup . '/' . str_replace('/', '__', $relative);
            if (is_file($target) && !copy($target, $name)) throw new RuntimeException('Backup failed');
            if (is_file($name)) chmod($name, 0600);
            $temp = tempnam(dirname($target), '.helix-update-');
            if ($temp === false || file_put_contents($temp, $body, LOCK_EX) !== strlen($body)) {
                throw new RuntimeException('Update staging failed');
            }
            chmod($temp, 0644);
            $staged[$relative] = [$temp, $target, $name, is_file($target)];
        }
        // Update the entry point and updater after assets, limiting mixed versions.
        foreach ($staged as $relative => [$temp, $target, $name, $existed]) {
            if ($relative === 'index.php' || $relative === 'auto-update.php') continue;
            if (!rename($temp, $target)) throw new RuntimeException('Update replacement failed');
            $changed[$relative] = [$target, $name, $existed];
        }
        foreach (['auto-update.php', 'index.php'] as $relative) {
            if (!isset($staged[$relative])) continue;
            [$temp, $target, $name, $existed] = $staged[$relative];
            if (!rename($temp, $target)) throw new RuntimeException('Update replacement failed');
            $changed[$relative] = [$target, $name, $existed];
        }
        $tempRevision = $private . '/.dashboard-revision-' . bin2hex(random_bytes(6));
        if (file_put_contents($tempRevision, $sha . "\n", LOCK_EX) === false
            || !rename($tempRevision, $currentPath)) {
            throw new RuntimeException('Cannot save update revision');
        }
        chmod($currentPath, 0600);
        return 'updated';
    } catch (Throwable $e) {
        // Best effort to restore every replaced file; keep private backups.
        foreach (array_reverse($changed, true) as [$target, $name, $existed]) {
            if ($existed && is_file($name)) copy($name, $target);
            elseif (!$existed) @unlink($target);
        }
        throw $e;
    } finally {
        foreach ($staged as [$temp]) if (is_file($temp)) @unlink($temp);
    }
}

/** One check every six hours when dashboard is visited; CLI cron may run hourly. */
function hpUpdateTick(string $root, string $private, bool $force = false, ?callable $fetch = null): string {
    if (!is_dir($private) || is_link($private)) throw new RuntimeException('Invalid updater state');
    $lockPath = $private . '/dashboard-update.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Updater lock unavailable');
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return 'locked';
        $statePath = $private . '/dashboard-update-check.json';
        $state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : [];
        if (!is_array($state)) $state = [];
        if (!$force && time() - (int)($state['checked_at'] ?? 0) < 6*3600) return 'throttled';
        try {
            $result = hpUpdateNow($root, $private, $fetch);
            // A freshly published commit may still be running CI.
            // Recheck in 15 minutes rather than waiting another six hours.
            $checked = $result === 'awaiting_ci' ? time() - 6*3600 + 900 : time();
            $status = ['checked_at' => $checked, 'result' => $result];
        } catch (Throwable $e) {
            $status = ['checked_at' => time()-5*3600, 'result' => 'error'];
            error_log('HelixPanel updater: ' . $e->getMessage());
            $result = 'error';
        }
        // Never expose revision state or internal errors via the public server.
        $temp = $private . '/.dashboard-check-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, json_encode($status, JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            chmod($temp, 0600);
            rename($temp, $statePath);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = __DIR__;
    $private = dirname($root) . '/.helixpanel-master';
    if (!is_file($private . '/config.php')) {
        fwrite(STDERR, "HelixPanel has not been installed.\n");
        exit(1);
    }
    $status = hpUpdateTick($root, $private, in_array('--force', $_SERVER['argv'] ?? [], true));
    echo "Dashboard update: $status\n";
    exit($status === 'error' ? 1 : 0);
}
