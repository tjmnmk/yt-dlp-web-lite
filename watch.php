<?php

require_once 'config.php';

/**
 * Check if current time is within low quality time period
 * @return bool true if current time is within low quality period
 */
function isLowQualityTime() {
    if (!LOW_QUALITY_ENABLED) {
        return false;
    }
    
    // Get timezone from config
    $timezone = new DateTimeZone(LOW_QUALITY_TIME_ZONE);
    
    // Get current time in the configured timezone
    $now = new DateTime('now', $timezone);
    $currentTime = $now->format('H:i:s');
    
    // Get start and end times from config
    $startTime = LOW_QUALITY_TIME_START;
    $endTime = LOW_QUALITY_TIME_END;
    
    // Handle case where end time is before start time (spans midnight)
    if ($endTime < $startTime) {
        // Time period spans midnight (e.g., 22:00:00 to 06:00:00)
        return ($currentTime >= $startTime || $currentTime <= $endTime);
    } else {
        // Normal time period within same day
        return ($currentTime >= $startTime && $currentTime <= $endTime);
    }
}

function yt_dlp_base_command() {
    $command = "yt-dlp --js-runtimes deno:" . DENO_PATH . " ";
    if (COOKIES_FILE) {
        $escaped_cookies_file = escapeshellarg(COOKIES_FILE);
        $command .= " --cookies " . $escaped_cookies_file . " ";
    }
    if (PO_TOKEN) {
        $full_po_arg = "youtube:po_token=" . PO_TOKEN;
        $po_arg = escapeshellarg($full_po_arg);
        $command .= " --extractor-args " . $po_arg . " ";
    }

    return $command;
}

// get video code from ?v
if (isset($_GET['v']) && is_string($_GET['v'])) {
    $video_code = $_GET['v'];
} else {
    die('No video URL provided');
}


// check if video code is valid, allowed a-zA-Z0-9-_, max 30 chars
if (strlen($video_code) > 30 || !preg_match('/^[a-zA-Z0-9-_]+$/', $video_code)) {
    die('Invalid video code');
}

$video_url = 'https://www.youtube.com/watch?v=' . $video_code;

// download video to video dir
$video_dir = 'videos/';
if (isLowQualityTime()) {
    $video_dir = 'videos_low/';
}

if (!is_dir($video_dir)) {
    mkdir($video_dir, 0755, true);
}
$video_file = $video_dir . $video_code . '.mp4';
// escape the video file name
$video_file_escped = escapeshellarg($video_file);
// escape the video url
$video_url_escaped = escapeshellarg($video_url);

// lock file to prevent parallel downloads of the same video
// waits until the other download finishes (or fails, lock released on exit)
$lock_file = fopen(sys_get_temp_dir() . '/yt_' . $video_code . '.lock', 'w');
if ($lock_file) {
    flock($lock_file, LOCK_EX);
}

// download if video file does not exist
// (re-check after lock, another request may have finished the download)
if (!file_exists($video_file)) {
    // download video using youtube-dl
    // quality 480p, --newline = one progress line per update
    $command = yt_dlp_base_command();
    if (!$DOWNLOAD_LIVESTREAMS) {
        $command .= ' --match-filter "live_status!=is_live & live_status!=is_upcoming" ';
    }
    // download only first video in playlist if url is a playlist
    $command .= " --playlist-items 1 --newline ";
    if (isLowQualityTime()) {
        $command .= " -f 'worstvideo*+worstaudio/worst' --merge-output-format mp4 -o $video_file_escped $video_url_escaped ";
    } else {
        $command .= " -S '+height:480' -f 'bv*+ba/best' --merge-output-format mp4 -o $video_file_escped $video_url_escaped ";
    }

    // stream progress to the browser
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no'); // disable nginx proxy buffering (if any)
    // disable output buffering so chunks reach the browser immediately
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Downloading...</title>";
    echo "<link rel=\"stylesheet\" href=\"style.css\"></head><body>";
    echo "<div style=\"width:80%;max-width:600px;text-align:center;font-family:system-ui,sans-serif\">";
    echo "<p id=\"status\">Connecting to YouTube...</p>";
    // padding: some browsers buffer small responses before rendering
    echo str_pad('', 4096, ' ', STR_PAD_RIGHT);
    flush();
    echo "<div style=\"background:#ddd;border-radius:6px;height:20px;overflow:hidden\">";
    echo "<div id=\"bar\" style=\"background:#d00;height:20px;width:0%;border-radius:6px;transition:width .4s\"></div>";
    echo "</div>";
    echo "<p><span id=\"pct\">0.0</span>%</p></div>";

    $proc = popen($command, 'r');
    $progress_output = [];
    if (!$proc) {
        error_log("Cannot start yt-dlp: " . $command);
        die('Error downloading video');
    }
    while (!feof($proc)) {
        $line = fgets($proc);
        if ($line === false) {
            break;
        }
        $progress_output[] = $line;
        // parse progress percentage, e.g. "[download]  42.3% of ..."
        if (preg_match('/\[download\]\s+([\d.]+)%/', $line, $m)) {
            $pct = min(100, (float)$m[1]);
            // update the bar, percentage and status text
            echo "<script>document.getElementById('status').textContent='Downloading video, please wait...';" .
                 "document.getElementById('bar').style.width='" . $pct . "%';" .
                 "document.getElementById('pct').textContent='" . $pct . "';</script>";
            // flush to the browser immediately
            flush();
        }
    }
    $return_var = pclose($proc);

    if ($return_var !== 0) {
        error_log("Error downloading video: " . implode("\n", $progress_output));
        die('<p>Error downloading video</p></body></html>');
    }
    echo "<p>Done, redirecting...</p>";
    echo "<script>window.location.replace('" . rawurlencode(basename($video_dir)) . "/" . rawurlencode($video_code) . ".mp4');</script>";
    echo "</body></html>";
    exit;
}

if (!file_exists($video_file)) {
    header('Content-Type: text/plain');

    // check if video is a livestream
    $command = yt_dlp_base_command();
    $command .= "--print \"%(live_status)s\" " . $video_url_escaped;
    exec($command, $output, $return_var);
    // live status is last line of output
    $live_status = end($output);
    echo "live_status: " . $live_status . "\n";
    if ($return_var === 0 && isset($live_status) && $live_status === 'is_live' && !$DOWNLOAD_LIVESTREAMS) {
        die('Video is a livestream, downloading livestreams is not allowed');
    }
    die('Video file does not exist after download attempt');
}

// release the download lock
flock($lock_file, LOCK_UN);
fclose($lock_file);

// redirect to video file by 302
header('Location: ' . $video_file);

