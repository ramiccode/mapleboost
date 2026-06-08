<?php
/**
 * Affiliate link cloaker + click logger.
 * Usage: <a href="/go?id=ownr">Sign up with Ownr</a>
 * Logs to /logs/clicks.csv (blocked from web by .htaccess).
 */
require_once __DIR__ . '/inc/config.php';

$id   = preg_replace('/[^a-z0-9_-]/i', '', $_GET['id'] ?? '');
$dest = aff_destination($id);

// Unknown id -> home
if (!$dest) {
    header('Location: /', true, 302);
    exit;
}

// Log the click (best-effort, never block the redirect)
@(function() use ($id) {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $line = [
        date('c'),
        $id,
        $_SERVER['HTTP_REFERER']    ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['REMOTE_ADDR']     ?? '',
    ];
    $fp = @fopen($dir . '/clicks.csv', 'a');
    if ($fp) {
        @fputcsv($fp, $line);
        @fclose($fp);
    }
})();

// 302 so search engines don't cache the destination
header('Cache-Control: no-store, max-age=0');
header('Location: ' . $dest, true, 302);
exit;
