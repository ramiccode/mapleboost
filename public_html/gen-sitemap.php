<?php
/**
 * Sitemap generator.
 * Run from CLI: `php gen-sitemap.php` (or browse to /gen-sitemap.php once, then delete or protect).
 * Walks the public_html tree, includes every public .html and .php page,
 * outputs sitemap.xml at the document root with extensionless URLs.
 *
 * Video support: any page containing an HTML5 <video> with a <source> (or a
 * src attribute on the <video> tag) gets a <video:video> entry. The thumbnail
 * is the first frame of the clip, written next to the video as <basename>.jpg.
 * Missing thumbnails are generated automatically with ffmpeg when available.
 */
require_once __DIR__ . '/inc/config.php';

$root = __DIR__;
$base = SITE_URL;
$includeExts  = ['html', 'php'];
$excludeDirs  = ['inc', 'logs', 'assets'];
$excludeFiles = ['go.php', 'gen-sitemap.php', '404.php', '500.php', '404.html', '500.html'];

$urls   = [];   // url => lastmod
$videos = [];   // url => [ [content_loc, thumbnail_loc, title, description], ... ]

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!in_array($file->getExtension(), $includeExts, true)) continue;

    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array(basename($rel), $excludeFiles, true)) continue;
    foreach ($excludeDirs as $d) {
        if (str_starts_with($rel, $d . '/')) continue 2;
    }

    // Convert path/to/page.(html|php) -> /path/to/page and */index.* -> /path/to/
    $url = '/' . preg_replace('/\.(html|php)$/', '', $rel);
    $url = preg_replace('#/index$#', '/', $url);
    if ($url !== '/' && substr($url, -1) === '/') {
        $url = rtrim($url, '/');
    }

    $lastmod = date('Y-m-d', $file->getMTime());
    if (!isset($urls[$url]) || $lastmod > $urls[$url]) {
        $urls[$url] = $lastmod;
    }

    $found = extract_videos($file->getPathname(), $rel, $base);
    if ($found) {
        $videos[$url] = array_merge($videos[$url] ?? [], $found);
    }
}

ksort($urls);

$hasVideo = !empty($videos);
$ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
if ($hasVideo) $ns .= ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';

$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<urlset $ns>\n";
foreach ($urls as $path => $lastmod) {
    $loc = htmlspecialchars($base . $path, ENT_XML1);
    $xml .= "  <url>\n    <loc>$loc</loc>\n    <lastmod>$lastmod</lastmod>\n";
    foreach ($videos[$path] ?? [] as $v) {
        $xml .= "    <video:video>\n";
        if (!empty($v['thumbnail_loc']))
            $xml .= "      <video:thumbnail_loc>" . htmlspecialchars($v['thumbnail_loc'], ENT_XML1) . "</video:thumbnail_loc>\n";
        $xml .= "      <video:title>" . htmlspecialchars($v['title'], ENT_XML1) . "</video:title>\n";
        $xml .= "      <video:description>" . htmlspecialchars($v['description'], ENT_XML1) . "</video:description>\n";
        if (!empty($v['content_loc']))
            $xml .= "      <video:content_loc>" . htmlspecialchars($v['content_loc'], ENT_XML1) . "</video:content_loc>\n";
        if (!empty($v['player_loc']))
            $xml .= "      <video:player_loc>" . htmlspecialchars($v['player_loc'], ENT_XML1) . "</video:player_loc>\n";
        if (!empty($v['duration']))
            $xml .= "      <video:duration>" . (int)$v['duration'] . "</video:duration>\n";
        $xml .= "    </video:video>\n";
    }
    $xml .= "  </url>\n";
}
$xml .= "</urlset>\n";

file_put_contents($root . '/sitemap.xml', $xml);

$msg = "Wrote " . count($urls) . " URLs to sitemap.xml";
if ($hasVideo) {
    $vc = array_sum(array_map('count', $videos));
    $msg .= " ($vc video entr" . ($vc === 1 ? 'y' : 'ies') . ")";
}
$msg .= "\n";

if (PHP_SAPI === 'cli') {
    echo $msg;
} else {
    header('Content-Type: text/plain');
    echo $msg;
}

/**
 * Scan one page for HTML5 video and return video sitemap entries.
 * Generates a first-frame .jpg thumbnail (via ffmpeg) if one isn't present.
 */
function extract_videos(string $absPath, string $rel, string $base): array {
    $html = file_get_contents($absPath);
    if ($html === false || stripos($html, '<video') === false) return [];

    $dir     = str_replace('\\', '/', dirname($rel));
    $dirUrl  = ($dir === '.' || $dir === '') ? '' : '/' . trim($dir, '/'); // '' when page in root
    $dirAbs  = dirname($absPath);

    // Page-level title/description fallbacks.
    $pageTitle = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
        $pageTitle = html_entity_decode($m[1], ENT_QUOTES);
    } elseif (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $pageTitle = trim(preg_replace('/\s*\|\s*MapleBoost\s*$/i', '', html_entity_decode($m[1], ENT_QUOTES)));
    }
    $pageDesc = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
        $pageDesc = html_entity_decode($m[1], ENT_QUOTES);
    }
    $pageImg = '';
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
        $pageImg = html_entity_decode($m[1], ENT_QUOTES);
    }

    $out  = [];
    $seen = [];   // dedupe by content/player loc

    // 1) Self-hosted HTML5 <video>.
    preg_match_all('/<video\b[^>]*>(.*?)<\/video>/is', $html, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $b) {
        $tag = $b[0];
        $inner = $b[1];

        // Source: first <source src> inside, else src attr on <video> tag.
        $srcEnc = '';
        if (preg_match('/<source\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $inner, $m)) {
            $srcEnc = $m[1];
        } elseif (preg_match('/<video\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $tag, $m)) {
            $srcEnc = $m[1];
        }
        if ($srcEnc === '') continue;
        if (preg_match('#^https?://#i', $srcEnc)) continue; // external host, skip

        $srcFile = rawurldecode($srcEnc);                  // on-disk relative name
        $thumbFile = preg_replace('/\.[^.\/]+$/', '.jpg', $srcFile);
        $thumbEnc  = preg_replace('/\.[^.\/]+$/', '.jpg', $srcEnc);

        $videoAbs = $dirAbs . '/' . $srcFile;
        $thumbAbs = $dirAbs . '/' . $thumbFile;

        // Generate a first-frame thumbnail if missing and ffmpeg is available.
        if (!is_file($thumbAbs) && is_file($videoAbs) && ffmpeg_bin()) {
            @exec(sprintf(
                '%s -y -i %s -frames:v 1 -q:v 2 %s 2>/dev/null',
                ffmpeg_bin(), escapeshellarg($videoAbs), escapeshellarg($thumbAbs)
            ));
        }

        // Per-video title via aria-label, else page title.
        $title = $pageTitle ?: 'Video';
        if (preg_match('/aria-label=["\']([^"\']+)["\']/i', $tag, $m)) {
            $title = html_entity_decode($m[1], ENT_QUOTES);
        }
        $desc = $pageDesc ?: $title;

        $contentLoc = $base . $dirUrl . '/' . $srcEnc;
        if (isset($seen[$contentLoc])) continue;
        $seen[$contentLoc] = true;

        $out[] = [
            'content_loc'   => $contentLoc,
            'thumbnail_loc' => $base . $dirUrl . '/' . $thumbEnc,
            'title'         => $title,
            'description'   => $desc,
            'duration'      => is_file($videoAbs) ? ffprobe_duration($videoAbs) : null,
        ];
    }

    // 2) og:video meta (file URL or external player).
    $ogVideo = '';
    foreach (['og:video:secure_url', 'og:video:url', 'og:video'] as $prop) {
        if (preg_match('/<meta[^>]+property=["\']' . preg_quote($prop, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) {
            $ogVideo = html_entity_decode($m[1], ENT_QUOTES);
            break;
        }
    }
    if ($ogVideo !== '' && !isset($seen[$ogVideo])) {
        $seen[$ogVideo] = true;
        $isFile = (bool) preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?|$)/i', $ogVideo);
        $out[] = [
            ($isFile ? 'content_loc' : 'player_loc') => $ogVideo,
            'thumbnail_loc' => $pageImg ?: '',
            'title'         => $pageTitle ?: 'Video',
            'description'   => $pageDesc ?: ($pageTitle ?: 'Video'),
        ];
    }

    // 3) YouTube / Vimeo iframe embeds -> player_loc.
    if (preg_match_all('#(?:youtube(?:-nocookie)?\.com/embed/|youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{11})#i', $html, $yt)) {
        foreach (array_unique($yt[1]) as $id) {
            $player = "https://www.youtube.com/embed/$id";
            if (isset($seen[$player])) continue;
            $seen[$player] = true;
            $out[] = [
                'player_loc'    => $player,
                'thumbnail_loc' => "https://i.ytimg.com/vi/$id/hqdefault.jpg",
                'title'         => $pageTitle ?: 'Video',
                'description'   => $pageDesc ?: ($pageTitle ?: 'Video'),
            ];
        }
    }
    if (preg_match_all('#(?:player\.)?vimeo\.com/(?:video/)?(\d+)#i', $html, $vm)) {
        foreach (array_unique($vm[1]) as $id) {
            $player = "https://player.vimeo.com/video/$id";
            if (isset($seen[$player])) continue;
            $seen[$player] = true;
            $out[] = [
                'player_loc'    => $player,
                'thumbnail_loc' => $pageImg ?: '',   // Vimeo thumb needs API; fall back to og:image
                'title'         => $pageTitle ?: 'Video',
                'description'   => $pageDesc ?: ($pageTitle ?: 'Video'),
            ];
        }
    }

    return $out;
}

function ffprobe_duration(string $videoAbs): ?int {
    $bin = ffprobe_bin();
    if (!$bin) return null;
    $out = @shell_exec(sprintf(
        '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
        $bin, escapeshellarg($videoAbs)
    ));
    $sec = (int) round((float) trim((string) $out));
    return ($sec >= 1 && $sec <= 28800) ? $sec : null;
}

function ffprobe_bin(): ?string {
    static $bin = false;
    if ($bin === false) {
        $p = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        $bin = $p !== '' ? $p : null;
    }
    return $bin;
}

function ffmpeg_bin(): ?string {
    static $bin = false;
    if ($bin === false) {
        $p = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        $bin = $p !== '' ? $p : null;
    }
    return $bin;
}
