<?php
/**
 * Check if string is a valid video ID (a-zA-Z0-9-_, max 30 chars)
 */
function isValidVideoId(string $id): bool {
    return preg_match('/^[a-zA-Z0-9-_]{1,30}$/', $id) === 1;
}

/**
 * Extract video ID from any YouTube URL format
 * Supported formats:
 *   - dQw4w9WgXcQ (raw ID)
 *   - https://www.youtube.com/watch?v=dQw4w9WgXcQ
 *   - https://youtu.be/dQw4w9WgXcQ
 *   - https://www.youtube.com/shorts/dQw4w9WgXcQ
 *   - https://www.youtube.com/embed/dQw4w9WgXcQ
 *   - https://m.youtube.com/watch?v=dQw4w9WgXcQ
 * @return string|null video ID or null if not found
 */
function extractVideoId(string $input): ?string {
    $input = trim($input);

    // empty input
    if ($input === '') {
        return null;
    }

    // raw video ID
    if (isValidVideoId($input)) {
        return $input;
    }

    // try to parse as URL
    $url = parse_url($input);
    if ($url === false || !isset($url['host'])) {
        return null;
    }

    // only youtube hosts
    $host = strtolower($url['host']);
    // strip www. / m. / music. prefix
    if (preg_match('/^(?:www|m|music)\.(.+)$/', $host, $m)) {
        $host = $m[1];
    }
    if (!in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
        return null;
    }

    $path = $url['path'] ?? '';

    if ($host === 'youtu.be') {
        // https://youtu.be/dQw4w9WgXcQ
        $id = ltrim($path, '/');
    } else {
        // take v param from query (?v=...)
        if (isset($url['query'])) {
            parse_str($url['query'], $query);
            if (isset($query['v']) && is_string($query['v']) && isValidVideoId($query['v'])) {
                return $query['v'];
            }
        }
        // /shorts/ID, /embed/ID, /live/ID, /v/ID
        if (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m) && isValidVideoId($m[1])) {
            return $m[1];
        }
        return null;
    }

    // validate extracted ID
    if (isValidVideoId($id)) {
        return $id;
    }
    return null;
}

$video_id = null;
$error = null;
// safe copy of input for the form field (may be missing or an array)
$url_input = is_string($_GET['url'] ?? null) ? $_GET['url'] : '';

if ($url_input !== '') {
    $video_id = extractVideoId($url_input);
    // validate the extracted ID once more (defense in depth)
    if ($video_id === null || !isValidVideoId($video_id)) {
        $video_id = null;
        $error = 'Neplatná URL YouTube videa';
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>YouTube video</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php if ($video_id !== null): ?>
        <script>
            // redirect to watch.php
            window.location.replace('watch.php?v=' + encodeURIComponent(<?= json_encode($video_id) ?>));
        </script>
        <noscript>
            <p><a href="watch.php?v=<?= htmlspecialchars($video_id, ENT_QUOTES, 'UTF-8') ?>">Pokračovat na video</a></p>
        </noscript>
    <?php else: ?>
        <form method="get" action="">
            <input type="text" name="url" placeholder="URL YouTube videa nebo ID" required
                   value="<?= htmlspecialchars($url_input, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Přehrát</button>
        </form>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
