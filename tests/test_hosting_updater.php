<?php
declare(strict_types=1);
define('HELIX_UPDATE_INTERNAL', true);
require __DIR__ . '/../hosting-master/public/auto-update.php';

function check(bool $valid, string $message): void {
    if (!$valid) throw new RuntimeException($message);
}
$workspace = sys_get_temp_dir() . '/helix-updater-tests-' . bin2hex(random_bytes(8));
$public = $workspace . '/public_html';
$private = $workspace . '/.helixpanel-master';
mkdir($public . '/assets', 0700, true);
mkdir($private, 0700);
try {
    $sha = str_repeat('a', 40);
    $blobs = [];
    foreach (HP_UPDATER_FILES as $file) {
        $blobs[$file] = 'new-' . $file;
        file_put_contents($public . '/' . $file, 'old-' . $file);
    }
    $tree = [];
    foreach ($blobs as $path => $body) {
        $tree[] = ['type'=>'blob', 'path'=>'hosting-master/public/' . $path,
                   'sha'=>hpUpdateBlobHash($body)];
    }
    $fetch = static function (string $url, int $size) use ($sha, $tree, $blobs): string {
        if (str_ends_with($url, '/commits/main')) {
            return json_encode(['sha'=>$sha], JSON_THROW_ON_ERROR);
        }
        if (str_contains($url, '/actions/workflows/')) {
            return json_encode(['workflow_runs'=>[
                ['head_sha'=>$sha,'conclusion'=>'success','event'=>'push']
            ]], JSON_THROW_ON_ERROR);
        }
        if (str_contains($url, '/git/trees/')) {
            return json_encode(['truncated'=>false,'tree'=>$tree], JSON_THROW_ON_ERROR);
        }
        foreach ($blobs as $name => $body) {
            if (str_ends_with($url, '/hosting-master/public/' . $name)) return $body;
        }
        throw new RuntimeException('unexpected request ' . $url);
    };

    check(hpUpdateNow($public, $private, $fetch)==='updated', 'first update failed');
    foreach ($blobs as $name=>$body) {
        check(file_get_contents($public.'/'.$name)===$body, 'update mismatch: '.$name);
        check(file_get_contents($private.'/update-backups/'.$sha.'/'.str_replace('/','__',$name))
              ==='old-'.$name, 'private rollback backup missing: '.$name);
    }
    check(hpUpdateNow($public, $private, $fetch)==='up_to_date', 'idempotence failed');
    check(trim(file_get_contents($private.'/dashboard-revision'))===$sha, 'revision pin missing');
    check(hpUpdateTick($public,$private,false,$fetch)==='up_to_date','tick failed');
    check(hpUpdateTick($public,$private,false,$fetch)==='throttled','throttle failed');

    $newSha=str_repeat('b',40);
    $broken=static function(string $url, int $size) use($fetch,$newSha): string {
        if(str_ends_with($url,'/commits/main'))return json_encode(['sha'=>$newSha]);
        if(str_contains($url,'/actions/workflows/'))
            return json_encode(['workflow_runs'=>[['head_sha'=>$newSha,'conclusion'=>'success','event'=>'push']]]);
        if(str_contains($url,'/git/trees/')) {
            $valid=json_decode($fetch('https://api.github.com/repos/hazhanhasani/HelixPanel-pro/git/trees/'.str_repeat('a',40),262144),true);
            return json_encode($valid);
        }
        if (str_ends_with($url, '/hosting-master/public/index.php')) return 'tampered index';
        return $fetch(str_replace('/'.$newSha.'/','/'.str_repeat('a',40).'/', $url),$size);
    };
    try {
        hpUpdateNow($public,$private,$broken);
        throw new RuntimeException('digest mismatch should fail');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(),'digest mismatch'),'wrong integrity failure');
    }
    foreach ($blobs as $name=>$body) check(file_get_contents($public.'/'.$name)===$body,
        'failed integrity verification modified live files');
    try {
        hpUpdateSafePath($public,'../../.helixpanel-master/config.php');
        throw new RuntimeException('path traversal accepted');
    } catch(RuntimeException $e) {
        check(str_contains($e->getMessage(),'not allowed'),'unexpected path error');
    }
    echo "Hosted auto updater: all tests passed\n";
} finally {
    $delete=function(string $path) use (&$delete): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $item) if ($item!=='.'&&$item!=='..') $delete($path.'/'.$item);
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $delete($workspace);
}
