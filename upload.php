<?php
// upload.php
header('Content-Type: application/json');
error_reporting(0);

$room = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['room'] ?? $_GET['room'] ?? 'default');
$targetDir = __DIR__ . "/uploads/$room";
if (!file_exists($targetDir)) mkdir($targetDir, 0755, true);

$ffmpegBin = __DIR__ . '/ffmpeg';
if (file_exists($ffmpegBin)) @chmod($ffmpegBin, 0755);

// آپلود فایل پیام صوتی
if (isset($_FILES['voice'])) {
    $cleanVoiceName = time() . '_voice_' . rand(100, 999) . '.webm';
    $voicePath = $targetDir . '/' . $cleanVoiceName;
    if (move_uploaded_file($_FILES['voice']['tmp_name'], $voicePath)) {
        echo json_encode(['status' => 'done', 'url' => "uploads/$room/" . $cleanVoiceName]);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// آپلود فایل زیرنویس
if (isset($_FILES['subtitle'])) {
    $ext = pathinfo($_FILES['subtitle']['name'], PATHINFO_EXTENSION);
    $cleanName = time() . '_sub.' . strtolower($ext);
    $finalSubPath = $targetDir . '/' . $cleanName;
    if (move_uploaded_file($_FILES['subtitle']['tmp_name'], $finalSubPath)) {
        echo json_encode(['status' => 'done', 'url' => "uploads/$room/" . $cleanName]);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// آپلود تکه‌ای ویدیو
$uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['uploadId'] ?? '');
$chunkIndex = isset($_POST['chunkIndex']) ? (int)$_POST['chunkIndex'] : 0;
$chunkSize = isset($_POST['chunkSize']) ? (int)$_POST['chunkSize'] : 0;
$totalChunks = isset($_POST['totalChunks']) ? (int)$_POST['totalChunks'] : 1;
$originalName = $_POST['fileName'] ?? 'video.mp4';

if (!$uploadId) $uploadId = 'up_' . md5($originalName);
$tempFilePath = $targetDir . '/' . $uploadId . '.part';

if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Chunk missing']);
    exit;
}

$out = fopen($tempFilePath, file_exists($tempFilePath) ? "c+b" : "wb");
if ($out) {
    fseek($out, $chunkIndex * $chunkSize);
    $in = fopen($_FILES['file']['tmp_name'], "rb");
    while ($buff = fread($in, 16384)) {
        fwrite($out, $buff);
    }
    fclose($in);
    fclose($out);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
    exit;
}

if (isset($_POST['isFinal']) && $_POST['isFinal'] === '1') {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $timestamp = time();
    $finalVideoName = $timestamp . '_' . $safeBase . '.' . strtolower($ext);
    $finalPath = $targetDir . '/' . $finalVideoName;
    rename($tempFilePath, $finalPath);

    $videoUrl = "uploads/$room/" . $finalVideoName;
    $subtitleUrl = null;

    if (file_exists($ffmpegBin) && is_executable($ffmpegBin)) {
        $extractedSubName = $timestamp . '_' . $safeBase . '_extracted.vtt';
        $extractedSubPath = $targetDir . '/' . $extractedSubName;

        $cmd = escapeshellcmd($ffmpegBin) . " -i " . escapeshellarg($finalPath) . " -map 0:s:0 -c:s webvtt " . escapeshellarg($extractedSubPath) . " -y 2>&1";
        @shell_exec($cmd);

        if (file_exists($extractedSubPath) && filesize($extractedSubPath) > 50) {
            $subtitleUrl = "uploads/$room/" . $extractedSubName;
        }
    }

    echo json_encode(['status' => 'done', 'url' => $videoUrl, 'subtitle_url' => $subtitleUrl]);
} else {
    echo json_encode(['status' => 'chunk_saved']);
}