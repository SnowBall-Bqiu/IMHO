<?php
/**
 * 图床API接口
 * 支持curl格式的POST请求，包含X-Auth-Key认证头和文件字段
 * 处理url参数，允许通过请求体自定义返回的基础URL
 * 返回指定格式的JSON响应
 */

// 引入配置文件
require_once 'config.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Key');

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => '只允许POST请求',
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 返回JSON响应
 */
function jsonResponse($success, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 记录日志
 */
function logMessage($message, $level = 'INFO') {
    global $configInstance;
    $log_dir = $configInstance->get('log_dir');
    $log_file = $log_dir . '/api_' . date('Y-m-d') . '.log';
    
    $timestamp = date('Y-m-d H:i:s');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log_entry = "[{$timestamp}] [{$level}] [IP:{$client_ip}] {$message}" . PHP_EOL;
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * 生成唯一文件名
 */
function generateUniqueFilename($user_id, $original_filename) {
    $extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
    $timestamp = time();
    $random = substr(md5(uniqid(rand(), true)), 0, 8);
    return "{$user_id}_{$timestamp}_img_{$random}.{$extension}";
}

/**
 * 验证文件类型
 */
function validateFileType($filename, $supported_types) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    foreach ($supported_types as $type => $extensions) {
        if (in_array($extension, $extensions)) {
            return true;
        }
    }
    
    return false;
}

/**
 * 保存上传记录到JSON文件
 */
function saveUploadRecord($user_info, $filename, $file_size, $original_filename) {
    global $configInstance;
    
    $records_file = $configInstance->get('json_records_file');
    $user_files_record = $configInstance->get('user_files_record');
    
    // 创建上传记录
    $record = [
        'id' => uniqid(),
        'user_id' => $user_info['user_id'],
        'username' => $user_info['username'],
        'filename' => $filename,
        'original_filename' => $original_filename,
        'file_size' => $file_size,
        'upload_time' => date('Y-m-d H:i:s'),
        'upload_method' => 'api',
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    // 保存到总记录文件
    $records = [];
    if (file_exists($records_file)) {
        $json_content = file_get_contents($records_file);
        $existing_records = json_decode($json_content, true);
        if (is_array($existing_records)) {
            $records = $existing_records;
        }
    }
    
    $records[] = $record;
    file_put_contents($records_file, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // 保存到用户文件记录
    $user_files = [];
    if (file_exists($user_files_record)) {
        $json_content = file_get_contents($user_files_record);
        $existing_user_files = json_decode($json_content, true);
        if (is_array($existing_user_files)) {
            $user_files = $existing_user_files;
        }
    }
    
    if (!isset($user_files[$user_info['user_id']])) {
        $user_files[$user_info['user_id']] = [];
    }
    
    $user_files[$user_info['user_id']][] = [
        'filename' => $filename,
        'original_filename' => $original_filename,
        'file_size' => $file_size,
        'upload_time' => date('Y-m-d H:i:s'),
        'upload_method' => 'api'
    ];
    
    file_put_contents($user_files_record, json_encode($user_files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // 保存图片来源映射
    $configInstance->saveImageSourceMap($filename, $user_info, 'api');
}

try {
    // 获取配置实例
    $configInstance = ImageHostConfig::getInstance();
    
    // 验证API Key
    $auth_key = $_SERVER['HTTP_X_AUTH_KEY'] ?? '';
    if (empty($auth_key)) {
        logMessage("API请求缺少认证头", 'ERROR');
        jsonResponse(false, '缺少认证头 X-Auth-Key', null, 401);
    }
    
    $user_info = $configInstance->getUserByApiKey($auth_key);
    if (!$user_info) {
        logMessage("无效的API Key: {$auth_key}", 'ERROR');
        jsonResponse(false, '无效的API Key', null, 401);
    }
    
    logMessage("用户 {$user_info['username']} 开始API上传", 'INFO');
    
    // 检查是否有文件上传
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = '文件上传失败';
        if (isset($_FILES['file']['error'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_msg = '文件大小超过限制';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_msg = '文件只有部分被上传';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_msg = '没有文件被上传';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $error_msg = '找不到临时文件夹';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $error_msg = '文件写入失败';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $error_msg = '文件上传被扩展程序阻止';
                    break;
            }
        }
        
        logMessage("文件上传错误: {$error_msg}", 'ERROR');
        jsonResponse(false, $error_msg, null, 400);
    }
    
    $uploaded_file = $_FILES['file'];
    $original_filename = $uploaded_file['name'];
    $file_size = $uploaded_file['size'];
    $tmp_name = $uploaded_file['tmp_name'];
    
    // 验证文件大小
    $max_file_size = $configInstance->get('max_file_size');
    if ($file_size > $max_file_size) {
        logMessage("文件大小超限: {$file_size} bytes", 'ERROR');
        jsonResponse(false, '文件大小超过限制 (' . round($max_file_size / 1024 / 1024, 2) . 'MB)', null, 400);
    }
    
    // 验证文件类型
    $supported_types = $configInstance->get('supported_types');
    if (!validateFileType($original_filename, $supported_types)) {
        logMessage("不支持的文件类型: {$original_filename}", 'ERROR');
        jsonResponse(false, '不支持的文件类型', null, 400);
    }
    
    // 生成新文件名
    $new_filename = generateUniqueFilename($user_info['user_id'], $original_filename);
    
    // 确保上传目录存在
    $upload_dir = $configInstance->get('upload_dir');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_path = $upload_dir . $new_filename;
    
    // 移动上传的文件
    if (!move_uploaded_file($tmp_name, $target_path)) {
        logMessage("文件移动失败: {$target_path}", 'ERROR');
        jsonResponse(false, '文件保存失败', null, 500);
    }
    
    // 保存上传记录
    saveUploadRecord($user_info, $new_filename, $file_size, $original_filename);
    
    // 处理URL参数和自定义基础URL
    $url_param = $_POST['url'] ?? $_GET['url'] ?? '2'; // 默认使用编号2
    $custom_base_url = $_POST['base_url'] ?? null; // 允许自定义基础URL
    
    // 获取返回URL映射
    $return_url_map = $configInstance->get('return_url_map');
    $base_url = $configInstance->get('base_url');
    
    // 确定最终的基础URL
    if ($custom_base_url) {
        // 使用自定义基础URL
        $final_base_url = rtrim($custom_base_url, '/') . '/';
        $processed_url = $final_base_url . $new_filename;
    } elseif (isset($return_url_map[$url_param])) {
        // 使用映射的URL
        $mapped_base_url = $return_url_map[$url_param];
        $processed_url = $mapped_base_url . '/i/' . $new_filename;
    } else {
        // 使用默认基础URL
        $processed_url = $base_url . $new_filename;
    }
    
    // 原始URL（始终使用默认基础URL）
    $original_url = $base_url . $new_filename;
    
    logMessage("用户 {$user_info['username']} 上传成功: {$new_filename} ({$file_size} bytes)", 'INFO');
    
    // 返回成功响应
    jsonResponse(true, '上传成功', [
        'url' => $processed_url,
        'original_url' => $original_url,
        'filename' => $new_filename,
        'size' => $file_size
    ]);
    
} catch (Exception $e) {
    logMessage("API异常: " . $e->getMessage(), 'ERROR');
    jsonResponse(false, '服务器内部错误', null, 500);
}
?>