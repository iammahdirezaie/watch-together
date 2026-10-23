<?php
// api.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
date_default_timezone_set('Asia/Tehran');

$db = new SQLite3(__DIR__ . '/data.db');
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode = WAL;');

$db->exec("
    CREATE TABLE IF NOT EXISTS rooms (
        room_id TEXT PRIMARY KEY,
        password TEXT DEFAULT '',
        created_at REAL
    );
    CREATE TABLE IF NOT EXISTS state (
        room_id TEXT PRIMARY KEY,
        video_src TEXT,
        subtitle_src TEXT,
        current_time REAL,
        status TEXT,
        last_updated REAL,
        updated_by TEXT,
        last_checkpoint REAL DEFAULT 0.0
    );
    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id TEXT,
        user TEXT,
        text TEXT,
        type TEXT DEFAULT 'text',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS online_users (
        room_id TEXT,
        username TEXT,
        last_ping REAL,
        PRIMARY KEY (room_id, username)
    );
");

// رفع تداخل ساختار پایگاه داده
@$db->exec("ALTER TABLE rooms ADD COLUMN password TEXT DEFAULT '';");
@$db->exec("ALTER TABLE state ADD COLUMN room_id TEXT;");
@$db->exec("ALTER TABLE state ADD COLUMN last_checkpoint REAL DEFAULT 0.0;");
@$db->exec("ALTER TABLE messages ADD COLUMN room_id TEXT;");
@$db->exec("ALTER TABLE messages ADD COLUMN type TEXT DEFAULT 'text';");
@$db->exec("ALTER TABLE online_users ADD COLUMN room_id TEXT;");

function deleteRoomDirectory($dir) {
    if (!file_exists($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? deleteRoomDirectory("$dir/$file") : @unlink("$dir/$file");
    }
    @rmdir($dir);
}

// پاک‌سازی خودکار اتاق‌ها و فایل‌ها پس از ۲۴ ساعت
$now = microtime(true);
$expiredRooms = @$db->query("SELECT room_id FROM rooms WHERE ($now - created_at) > 86400");
if ($expiredRooms) {
    while ($r = $expiredRooms->fetchArray(SQLITE3_ASSOC)) {
        $expId = $r['room_id'];
        $db->exec("DELETE FROM rooms WHERE room_id = '$expId'");
        $db->exec("DELETE FROM state WHERE room_id = '$expId'");
        $db->exec("DELETE FROM messages WHERE room_id = '$expId'");
        $db->exec("DELETE FROM online_users WHERE room_id = '$expId'");
        deleteRoomDirectory(__DIR__ . "/uploads/$expId");
    }
}

$action = $_GET['action'] ?? '';
$room = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['room'] ?? '');

// ساخت اتاق
if ($action === 'create_room') {
    $input = json_decode(file_get_contents('php://input'), true);
    $reqName = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $input['room'] ?? ''));
    $pass = trim($input['password'] ?? '');

    if (!$reqName) $reqName = 'room_' . bin2hex(random_bytes(3));

    $exists = (int)$db->querySingle("SELECT COUNT(*) FROM rooms WHERE room_id = '$reqName'");
    if ($exists > 0) {
        echo json_encode(['success' => false, 'message' => 'این شناسه اتاق از قبل ثبت شده است!']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO rooms (room_id, password, created_at) VALUES (:r, :p, :t)");
    $stmt->bindValue(':r', $reqName, SQLITE3_TEXT);
    $stmt->bindValue(':p', $pass, SQLITE3_TEXT);
    $stmt->bindValue(':t', $now, SQLITE3_FLOAT);
    $stmt->execute();

    $stateStmt = $db->prepare("INSERT OR REPLACE INTO state (room_id, video_src, subtitle_src, current_time, status, last_updated, updated_by, last_checkpoint) VALUES (:r, '', '', 0.0, 'paused', :t, 'سیستم', 0.0)");
    $stateStmt->bindValue(':r', $reqName, SQLITE3_TEXT);
    $stateStmt->bindValue(':t', $now, SQLITE3_FLOAT);
    $stateStmt->execute();

    @mkdir(__DIR__ . "/uploads/$reqName", 0755, true);
    echo json_encode(['success' => true, 'room' => $reqName]);
    exit;
}

// بررسی دسترسی و اعتبارسنجی رمز
if ($action === 'auth_room') {
    $input = json_decode(file_get_contents('php://input'), true);
    $reqName = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $input['room'] ?? ''));
    $pass = trim($input['password'] ?? '');

    $roomStmt = $db->prepare("SELECT * FROM rooms WHERE room_id = :r");
    $roomStmt->bindValue(':r', $reqName, SQLITE3_TEXT);
    $roomData = $roomStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$roomData) {
        echo json_encode(['success' => false, 'exists' => false, 'message' => 'اتاق یافت نشد یا منقضی شده است.']);
        exit;
    }

    $realPass = $roomData['password'] ?? '';
    if ($realPass !== '' && $realPass !== $pass) {
        echo json_encode(['success' => false, 'exists' => true, 'need_password' => true, 'message' => 'رمز عبور اشتباه است!']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// وضعیت اتاق
if ($action === 'check_room_status') {
    $roomStmt = $db->prepare("SELECT * FROM rooms WHERE room_id = :r");
    $roomStmt->bindValue(':r', $room, SQLITE3_TEXT);
    $roomData = $roomStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$roomData) {
        echo json_encode(['exists' => false]);
    } else {
        $hasPass = !empty($roomData['password']);
        echo json_encode(['exists' => true, 'has_password' => $hasPass]);
    }
    exit;
}

// دریافت رمز برای لینک جادویی
if ($action === 'get_share_key') {
    $roomStmt = $db->prepare("SELECT password FROM rooms WHERE room_id = :r");
    $roomStmt->bindValue(':r', $room, SQLITE3_TEXT);
    $roomData = $roomStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $pass = $roomData['password'] ?? '';
    echo json_encode(['success' => true, 'key' => $pass]);
    exit;
}

if (!$room) {
    echo json_encode(['error' => 'No room specified']);
    exit;
}

$roomUploadDir = __DIR__ . "/uploads/$room";
if (!file_exists($roomUploadDir)) @mkdir($roomUploadDir, 0755, true);

// پخش لینک مستقیم خارجی از پروکسی
if ($action === 'set_external_stream') {
    $input = json_decode(file_get_contents('php://input'), true);
    $url = trim($input['url'] ?? '');
    $user = htmlspecialchars($input['user'] ?? 'کاربر');

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'لینک نامعتبر است.']);
        exit;
    }

    $detectedSub = null;
    $ffmpegBin = __DIR__ . '/ffmpeg';
    if (file_exists($ffmpegBin)) {
        @chmod($ffmpegBin, 0755);
        $subHash = 'sub_' . substr(md5($url), 0, 10) . '.vtt';
        $subPath = $roomUploadDir . '/' . $subHash;
        
        $cmd = escapeshellcmd($ffmpegBin) . " -timeout 5000000 -i " . escapeshellarg($url) . " -map 0:s:0 -c:s webvtt " . escapeshellarg($subPath) . " -y 2>&1";
        @shell_exec($cmd);

        if (file_exists($subPath) && filesize($subPath) > 50) {
            $detectedSub = "uploads/$room/" . $subHash;
        }
    }

    $streamProxyUrl = 'proxy.php?url=' . urlencode($url);

    $stmt = $db->prepare("UPDATE state SET video_src = :src, subtitle_src = :sub, current_time = 0, status = 'paused', last_checkpoint = 0, last_updated = :ts, updated_by = :user WHERE room_id = :r");
    $stmt->bindValue(':src', $streamProxyUrl, SQLITE3_TEXT);
    $stmt->bindValue(':sub', $detectedSub ? $detectedSub : '', SQLITE3_TEXT);
    $stmt->bindValue(':ts', microtime(true), SQLITE3_FLOAT);
    $stmt->bindValue(':user', $user, SQLITE3_TEXT);
    $stmt->bindValue(':r', $room, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'video_src' => $streamProxyUrl,
        'subtitle_src' => $detectedSub,
        'sub_found' => !empty($detectedSub)
    ]);
    exit;
}

// ثبت هارت‌بیت
if ($action === 'ping') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user = trim(htmlspecialchars($input['user'] ?? ''));
    if ($user) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO online_users (room_id, username, last_ping) VALUES (:r, :u, :t)");
        $stmt->bindValue(':r', $room, SQLITE3_TEXT);
        $stmt->bindValue(':u', $user, SQLITE3_TEXT);
        $stmt->bindValue(':t', $now, SQLITE3_FLOAT);
        $stmt->execute();
    }
    echo json_encode(['ok' => true]);
    exit;
}

// لیست فایل‌ها
if ($action === 'list_files') {
    $files = [];
    $totalBytes = 0;
    if (file_exists($roomUploadDir)) {
        $scan = scandir($roomUploadDir);
        foreach ($scan as $file) {
            if ($file === '.' || $file === '..' || str_ends_with($file, '.part')) continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4', 'mkv', 'webm', 'vtt', 'srt'])) {
                $fSize = filesize($roomUploadDir . '/' . $file);
                $totalBytes += $fSize;
                $files[] = [
                    'real_name' => $file,
                    'display_name' => preg_replace('/^\d+_/', '', $file),
                    'url' => "uploads/$room/" . $file,
                    'type' => in_array($ext, ['vtt', 'srt']) ? 'sub' : 'video',
                    'size' => round($fSize / (1024 * 1024), 2) . ' MB'
                ];
            }
        }
    }
    echo json_encode(['files' => array_reverse($files), 'total_size' => round($totalBytes / (1024 * 1024), 2) . ' MB']);
    exit;
}

// استخراج دستی زیرنویس با FFmpeg
if ($action === 'extract_subtitle') {
    $input = json_decode(file_get_contents('php://input'), true);
    $fileName = basename($input['file_name'] ?? '');
    $filePath = $roomUploadDir . '/' . $fileName;
    $ffmpegBin = __DIR__ . '/ffmpeg';

    if (!$fileName || !file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'فایل یافت نشد.']);
        exit;
    }
    @chmod($ffmpegBin, 0755);

    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    $outputSubName = $nameWithoutExt . '_extracted.vtt';
    $outputSubPath = $roomUploadDir . '/' . $outputSubName;

    $cmd = escapeshellcmd($ffmpegBin) . " -i " . escapeshellarg($filePath) . " -map 0:s:0 -c:s webvtt " . escapeshellarg($outputSubPath) . " -y 2>&1";
    @shell_exec($cmd);

    if (file_exists($outputSubPath) && filesize($outputSubPath) > 50) {
        $subUrl = "uploads/$room/" . $outputSubName;
        $user = htmlspecialchars($input['user'] ?? 'کاربر');
        $stmt = $db->prepare("UPDATE state SET subtitle_src = :sub, last_updated = :ts, updated_by = :user WHERE room_id = :r");
        $stmt->bindValue(':sub', $subUrl, SQLITE3_TEXT);
        $stmt->bindValue(':ts', microtime(true), SQLITE3_FLOAT);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':r', $room, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true, 'subtitle_url' => $subUrl]);
    } else {
        echo json_encode(['success' => false, 'message' => 'زیرنویس داخلی یافت نشد.']);
    }
    exit;
}

// حذف فایل
if ($action === 'delete_file') {
    $input = json_decode(file_get_contents('php://input'), true);
    $fileName = basename($input['file_name'] ?? '');
    $filePath = $roomUploadDir . '/' . $fileName;
    if ($fileName && file_exists($filePath)) {
        unlink($filePath);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'File not found']);
    }
    exit;
}

// پاک‌سازی چت
if ($action === 'clear_chat') {
    $stmt = $db->prepare("DELETE FROM messages WHERE room_id = :r");
    $stmt->bindValue(':r', $room, SQLITE3_TEXT);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

// استریم رویدادها (SSE) همراه با ارسال اسامی کاربران آنلاین
if ($action === 'stream') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $lastMsgId = isset($_GET['last_msg_id']) ? (int)$_GET['last_msg_id'] : 0;

    while (true) {
        if (connection_aborted()) break;

        $now = microtime(true);
        $delUsers = $db->prepare("DELETE FROM online_users WHERE room_id = :r AND (:t - last_ping) > 6.0");
        $delUsers->bindValue(':r', $room, SQLITE3_TEXT);
        $delUsers->bindValue(':t', $now, SQLITE3_FLOAT);
        $delUsers->execute();

        // دریافت لیست اسامی کاربران آنلاین
        $userQuery = $db->prepare("SELECT username FROM online_users WHERE room_id = :r");
        $userQuery->bindValue(':r', $room, SQLITE3_TEXT);
        $userRes = $userQuery->execute();
        $onlineNames = [];
        while ($u = $userRes->fetchArray(SQLITE3_ASSOC)) {
            $onlineNames[] = $u['username'];
        }

        $stateStmt = $db->prepare("SELECT * FROM state WHERE room_id = :r");
        $stateStmt->bindValue(':r', $room, SQLITE3_TEXT);
        $state = $stateStmt->execute()->fetchArray(SQLITE3_ASSOC);

        $msgStmt = $db->prepare("SELECT * FROM messages WHERE room_id = :r AND id > :id ORDER BY id ASC");
        $msgStmt->bindValue(':r', $room, SQLITE3_TEXT);
        $msgStmt->bindValue(':id', $lastMsgId, SQLITE3_INTEGER);
        $res = $msgStmt->execute();

        $messages = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
            $lastMsgId = $row['id'];
        }

        $cntMsg = $db->prepare("SELECT COUNT(*) FROM messages WHERE room_id = :r");
        $cntMsg->bindValue(':r', $room, SQLITE3_TEXT);
        $totalMsgCount = (int)$cntMsg->execute()->fetchArray(SQLITE3_NUM)[0];
        $cleared = ($totalMsgCount === 0 && $lastMsgId > 0);
        if ($cleared) $lastMsgId = 0;

        echo "data: " . json_encode([
            'state' => $state,
            'online_count' => count($onlineNames),
            'online_users' => $onlineNames,
            'new_messages' => $messages,
            'last_msg_id' => $lastMsgId,
            'cleared' => $cleared,
            'server_time' => $now
        ]) . "\n\n";

        @ob_flush();
        flush();
        usleep(250000);
    }
    exit;
}

// ثبت وضعیت پلیر
if ($action === 'update_state') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user = htmlspecialchars($input['user'] ?? 'کاربر');
    $time = isset($input['time']) ? (float)$input['time'] : null;
    $status = in_array($input['status'] ?? '', ['playing', 'paused']) ? $input['status'] : null;
    $video_src = $input['video_src'] ?? null;
    $subtitle_src = $input['subtitle_src'] ?? null;
    $restore_checkpoint = !empty($input['restore_checkpoint']);

    if ($restore_checkpoint) {
        $cpStmt = $db->prepare("SELECT last_checkpoint FROM state WHERE room_id = :r");
        $cpStmt->bindValue(':r', $room, SQLITE3_TEXT);
        $lastCp = (float)$cpStmt->execute()->fetchArray(SQLITE3_NUM)[0];

        $stmt = $db->prepare("UPDATE state SET current_time = :time, status = 'paused', last_updated = :ts, updated_by = :user WHERE room_id = :r");
        $stmt->bindValue(':time', $lastCp, SQLITE3_FLOAT);
        $stmt->bindValue(':ts', microtime(true), SQLITE3_FLOAT);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':r', $room, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true, 'time' => $lastCp]);
        exit;
    }

    if ($video_src !== null) {
        $stmt = $db->prepare("UPDATE state SET video_src = :src, current_time = 0, status = 'paused', last_checkpoint = 0, last_updated = :ts, updated_by = :user WHERE room_id = :r");
        $stmt->bindValue(':src', $video_src, SQLITE3_TEXT);
    } elseif ($subtitle_src !== null) {
        $stmt = $db->prepare("UPDATE state SET subtitle_src = :sub, last_updated = :ts, updated_by = :user WHERE room_id = :r");
        $stmt->bindValue(':sub', $subtitle_src, SQLITE3_TEXT);
    } else {
        $cpSql = ($status === 'paused' && $time > 1.0) ? ", last_checkpoint = :time" : "";
        $stmt = $db->prepare("UPDATE state SET current_time = :time, status = :status, last_updated = :ts, updated_by = :user $cpSql WHERE room_id = :r");
        $stmt->bindValue(':time', (float)$time, SQLITE3_FLOAT);
        $stmt->bindValue(':status', (string)$status, SQLITE3_TEXT);
    }
    $stmt->bindValue(':ts', microtime(true), SQLITE3_FLOAT);
    $stmt->bindValue(':user', $user, SQLITE3_TEXT);
    $stmt->bindValue(':r', $room, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

// ارسال چت و ری‌اکشن
if ($action === 'send_message') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user = trim(htmlspecialchars($input['user'] ?? ''));
    $text = trim($input['text'] ?? '');
    $type = in_array($input['type'] ?? '', ['reaction', 'text', 'voice']) ? $input['type'] : 'text';

    if ($user !== '' && $text !== '') {
        $stmt = $db->prepare("INSERT INTO messages (room_id, user, text, type) VALUES (:r, :user, :text, :type)");
        $stmt->bindValue(':r', $room, SQLITE3_TEXT);
        $stmt->bindValue(':user', $user, SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid data']);
    }
    exit;
}