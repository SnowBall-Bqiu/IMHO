<?php
// 加载统一配置
require_once 'config.php';
$configInstance = ImageHostConfig::getInstance();

// 提取配置变量
$upload_dir = $configInstance->get('upload_dir');
$base_url = $configInstance->get('base_url');
$supported_types = $configInstance->get('supported_types');
$all_supported_types = array_merge(...array_values($supported_types));
$max_file_size = $configInstance->get('max_file_size');

// 启动会话并设置安全参数
if (session_status() === PHP_SESSION_NONE) {
    // 设置会话安全参数
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    session_start();
    
    // 防止会话固定攻击
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
}

// 检查API Key鉴权
function check_auth($configInstance) {
    // 检查是否已通过API Key验证，并验证会话完整性
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && 
        isset($_SESSION['user_info']) && isset($_SESSION['api_key'])) {
        
        // 验证当前API Key是否仍然有效
        $current_user = $configInstance->getUserByApiKey($_SESSION['api_key']);
        if ($current_user && $current_user['user_id'] === $_SESSION['user_info']['user_id']) {
            return $_SESSION['user_info'];
        } else {
            // API Key无效或用户信息不匹配，清除会话
            session_destroy();
            session_start();
        }
    }
    
    // 检查POST提交的API Key
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key'])) {
        $api_key = trim($_POST['api_key']);
        $user_info = $configInstance->getUserByApiKey($api_key);
        
        if ($user_info) {
            $_SESSION['authenticated'] = true;
            $_SESSION['user_info'] = $user_info;
            $_SESSION['api_key'] = $api_key;
            return $user_info;
        } else {
            $error = 'API Key无效或已被禁用';
            show_login_form($error);
            exit;
        }
    }
    
    // 显示登录表单
    show_login_form();
    exit;
}

// 显示API Key登录表单
function show_login_form($error = '') {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>IMHO Admin - API Key登录</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
            <div class="text-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">IMHO Admin - API Key登录</h1>
                <p class="text-gray-600 mt-2">请输入佬的API Key进行登录</p>
            </div>
            
            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-4">
                    <label for="api_key" class="block text-sm font-medium text-gray-700 mb-2">
                        API Key
                    </label>
                    <input type="password" 
                           id="api_key" 
                           name="api_key" 
                           required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="请输入佬的API Key">
                </div>
                
                <button type="submit" 
                        class="w-full bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200">
                    登录
                </button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// 退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 用户文件记录函数
function saveUserFileRecord($user_id, $username, $filename, $configInstance) {
    $user_files_file = $configInstance->get('user_files_record');
    
    // 使用文件锁防止并发写入冲突
    $lock_file = $user_files_file . '.lock';
    $lock_handle = fopen($lock_file, 'w');
    
    if (!$lock_handle || !flock($lock_handle, LOCK_EX)) {
        error_log("无法获取文件锁: " . $lock_file);
        if ($lock_handle) {
            fclose($lock_handle);
        }
        return false;
    }
    
    try {
        // 读取现有记录
        $user_files = [];
        if (file_exists($user_files_file)) {
            $json_content = file_get_contents($user_files_file);
            if ($json_content !== false) {
                $existing_records = json_decode($json_content, true);
                if (is_array($existing_records)) {
                    $user_files = $existing_records;
                }
            }
        }
        
        // 使用user_id作为主键，确保数据结构完整
        if (!isset($user_files[$user_id]) || !is_array($user_files[$user_id])) {
            $user_files[$user_id] = [
                'username' => $username,
                'files' => []
            ];
        }
        
        // 确保files数组存在且为数组类型
        if (!isset($user_files[$user_id]['files']) || !is_array($user_files[$user_id]['files'])) {
            $user_files[$user_id]['files'] = [];
        }
        
        // 确保username字段存在
        if (!isset($user_files[$user_id]['username'])) {
            $user_files[$user_id]['username'] = $username;
        }
        
        // 添加文件名（仅文件名），避免重复
        if (!in_array($filename, $user_files[$user_id]['files'])) {
            $user_files[$user_id]['files'][] = $filename;
            $user_files[$user_id]['last_upload'] = date('Y-m-d H:i:s');
            $user_files[$user_id]['file_count'] = count($user_files[$user_id]['files']);
        }
        
        // 保存到JSON文件，使用原子写入
        $json_data = json_encode($user_files, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $temp_file = $user_files_file . '.tmp.' . uniqid();
        
        if (file_put_contents($temp_file, $json_data, LOCK_EX) !== false) {
            if (rename($temp_file, $user_files_file)) {
                return true;
            } else {
                unlink($temp_file);
                error_log("无法重命名临时文件: " . $temp_file);
                return false;
            }
        } else {
            error_log("无法写入临时文件: " . $temp_file);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("saveUserFileRecord异常: " . $e->getMessage());
        return false;
    } finally {
        // 释放文件锁
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }
    }
}

// 执行鉴权
$current_user = check_auth($configInstance);

// 获取文件列表的函数
function get_files_list($upload_dir, $base_url, $supported_types, $all_supported_types, $current_user, $configInstance) {
    $files_list = [];
    if (is_dir($upload_dir)) {
        $files = scandir($upload_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && is_file($upload_dir . $file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $all_supported_types)) {
                    $type = 'other';
                    foreach ($supported_types as $key => $extensions) {
                        if (in_array($ext, $extensions)) {
                            $type = $key;
                            break;
                        }
                    }
                    
                    // 获取图片来源信息
                    $source_info = $configInstance->getImageSource($file);
                    
                    // 权限检查：普通用户只能看到自己上传的文件
                    $can_manage = false;
                    if ($current_user['role'] === 'admin') {
                        $can_manage = true;
                    } elseif ($current_user['role'] === 'user') {
                        // 检查文件是否属于当前用户
                        $can_manage = ($source_info['user_id'] === $current_user['user_id']);
                    }
                    
                    // 只显示用户有权限查看的文件
                    if ($can_manage || $current_user['role'] === 'admin') {
                        $files_list[] = [
                            'name' => $file,
                            'url' => $base_url . $file,
                            'type' => $type,
                            'extension' => $ext,
                            'can_manage' => $can_manage,
                            'uploader' => $source_info['username'] ?? $source_info['user_id'],
                            'upload_time' => $source_info['upload_time'] ?? 'unknown',
                            'upload_method' => $source_info['upload_method'] ?? 'unknown',
                            'source_info' => $source_info
                        ];
                    }
                }
            }
        }
    }
    return $files_list;
}

// 处理删除请求
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['filename'])) {
    header('Content-Type: application/json');
    $filename = $_POST['filename'];
    $filepath = $upload_dir . $filename;
    
    // 权限检查
    $can_delete = false;
    if ($current_user['role'] === 'admin') {
        $can_delete = true;
    } elseif ($current_user['role'] === 'user') {
        $can_delete = strpos($filename, $current_user['username'] . '_') === 0;
    }
    
    if (!$can_delete) {
        echo json_encode(['success' => false, 'error' => '佬没有权限删除此文件']);
        exit;
    }
    
    if (file_exists($filepath) && is_file($filepath)) {
        if (unlink($filepath)) {
            echo json_encode(['success' => true, 'message' => '文件删除成功']);
        } else {
            echo json_encode(['success' => false, 'error' => '文件删除失败']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => '文件不存在']);
    }
    exit;
}

// 处理AJAX刷新请求
if (isset($_GET['action']) && $_GET['action'] === 'refresh') {
    header('Content-Type: application/json');
    try {
        $files_list = get_files_list($upload_dir, $base_url, $supported_types, $all_supported_types, $current_user, $configInstance);
        echo json_encode(['success' => true, 'files' => $files_list, 'user' => $current_user]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 处理文件上传
$message = "";
$file_url = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["file"])) {
    $file = $_FILES["file"];
    
    if ($file["error"] !== UPLOAD_ERR_OK) {
        $message = "上传失败，错误代码: " . $file["error"];
    } else {
        if ($file["size"] > $max_file_size) {
            $message = "文件大小超过限制（最大" . ($max_file_size / 1024 / 1024) . "MB）";
        } else {
            $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
            if (!in_array($ext, $all_supported_types)) {
                $message = "不支持的文件类型";
            } else {
                // 生成简洁的文件名
                $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
                $original_name = pathinfo($file["name"], PATHINFO_FILENAME);
                
                // 清理原始文件名，只保留字母数字和连字符
                $clean_name = preg_replace("/[^A-Za-z0-9-]/", "", $original_name);
                $clean_name = substr($clean_name, 0, 20); // 限制长度
                
                // 生成简洁的文件名格式：用户名_日期时间_清理后的原名.扩展名
                $date_time = date('YmdHis');
                $filename = $current_user['username'] . "_" . $date_time . "_" . $clean_name . "." . $ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file["tmp_name"], $filepath)) {
                    // 记录用户上传的文件
                    saveUserFileRecord($current_user['user_id'], $current_user['username'], $filename, $configInstance);
                    
                    // 保存图片来源映射
                    $configInstance->saveImageSourceMap($filename, $current_user, 'web');
                    
                    // 对于 FilePond 上传，直接返回URL
                    if (isset($_FILES['file'])) {
                        echo $base_url . $filename;
                        exit;
                    }
                    // 对于普通表单上传，设置消息
                    $message = "文件上传成功！";
                    $file_url = $base_url . $filename;
                } else {
                    $message = "文件移动失败，请检查目录权限";
                }
            }
        }
    }
}

// 获取初始文件列表
$files_list = get_files_list($upload_dir, $base_url, $supported_types, $all_supported_types, $current_user, $configInstance);
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web UI</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js -->
    <script defer src="https://testingcf.jsdelivr.net/npm/alpinejs@3.9.0/dist/cdn.min.js"></script>
    <script>
        // Alpine.js 组件定义
        document.addEventListener('alpine:init', () => {
            // 图片库组件
            Alpine.data('imageGallery', () => ({
                isLoading: false,
                
                refreshImages() {
                    this.isLoading = true;
                    fetch('?action=refresh')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // 刷新图片列表
                                console.log('图片列表已刷新');
                                location.reload(); // 简单处理：刷新页面
                            } else {
                                console.error('刷新失败:', data.error);
                                alert('刷新失败: ' + data.error);
                            }
                        })
                        .catch(error => {
                            console.error('刷新请求错误:', error);
                            alert('刷新请求错误');
                        })
                        .finally(() => {
                            this.isLoading = false;
                        });
                }
            }));
        });
        
        // API Key管理相关函数
        function showApiKeyManager() {
            document.getElementById('apiKeyModal').classList.remove('hidden');
        }
        
        function hideApiKeyManager() {
            document.getElementById('apiKeyModal').classList.add('hidden');
        }
        
        function showCurrentApiKey() {
            document.getElementById('currentApiKeyModal').classList.remove('hidden');
        }
        
        function hideCurrentApiKey() {
            document.getElementById('currentApiKeyModal').classList.add('hidden');
        }
        
        function toggleApiKeyVisibility(button) {
            const input = button.previousElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = '隐藏';
            } else {
                input.type = 'password';
                button.textContent = '显示';
            }
        }
        
        function toggleCurrentApiKeyVisibility() {
            const input = document.getElementById('currentApiKeyInput');
            const button = input.nextElementSibling;
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = '隐藏';
            } else {
                input.type = 'password';
                button.textContent = '显示';
            }
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('已复制到剪贴板');
            }).catch(err => {
                console.error('复制失败:', err);
                alert('复制失败');
            });
        }
        
        function addNewUser() {
            const userId = document.getElementById('newUserId').value.trim();
            const username = document.getElementById('newUsername').value.trim();
            const role = document.getElementById('newUserRole').value;
            
            if (!userId || !username) {
                alert('请填写用户ID和用户名');
                return;
            }
            
            // 发送AJAX请求添加用户
            fetch('api_key_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'add_user',
                    user_id: userId,
                    username: username,
                    role: role
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('用户添加成功，API Key: ' + data.api_key);
                    location.reload(); // 刷新页面
                } else {
                    alert('添加失败: ' + data.error);
                }
            })
            .catch(error => {
                console.error('请求错误:', error);
                alert('请求错误');
            });
        }
        
        function disableApiKey(apiKey) {
            if (!confirm('确定要禁用此API Key吗？')) {
                return;
            }
            
            // 发送AJAX请求禁用API Key
            fetch('api_key_manager.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'disable_key',
                    api_key: apiKey
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('API Key已禁用');
                    location.reload(); // 刷新页面
                } else {
                    alert('禁用失败: ' + data.error);
                }
            })
            .catch(error => {
                console.error('请求错误:', error);
                alert('请求错误');
            });
        }
        
        // 复制所有图片URL
        function copyAllImageUrls() {
            // 获取所有图片元素
            const imageElements = document.querySelectorAll('.image-item img');
            if (imageElements.length === 0) {
                alert('没有找到图片');
                return;
            }
            
            // 提取所有图片URL
            const urls = Array.from(imageElements).map(img => img.src);
            const urlText = urls.join('\n');
            
            // 复制到剪贴板
            navigator.clipboard.writeText(urlText).then(() => {
                alert(`已复制 ${urls.length} 个图片URL到剪贴板`);
            }).catch(err => {
                console.error('复制失败:', err);
                alert('复制失败');
            });
        }
        
        // 删除图片函数
        function deleteImage(filename) {
            if (!confirm('确定要删除这个文件吗？')) {
                return;
            }
            
            // 发送删除请求
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('filename', filename);
            
            fetch('./', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('文件删除成功');
                    // 刷新页面或移除对应的图片元素
                    location.reload();
                } else {
                    alert('删除失败: ' + (data.error || '未知错误'));
                }
            })
            .catch(error => {
                console.error('删除请求错误:', error);
                alert('删除请求失败');
            });
        }
        
        // 处理浏览器扩展相关的错误，避免控制台报错
        window.addEventListener('error', function(event) {
            // 忽略与浏览器扩展相关的错误
            if (event.message && event.message.includes('Extension context invalidated')) {
                event.preventDefault();
                return false;
            }
        });
        
        // 处理未捕获的Promise拒绝
        window.addEventListener('unhandledrejection', function(event) {
            // 忽略与浏览器扩展相关的Promise拒绝
            if (event.reason && event.reason.message && 
                (event.reason.message.includes('Extension context invalidated') ||
                 event.reason.message.includes('message port closed'))) {
                event.preventDefault();
                return false;
            }
        });
    </script>

    <!-- FilePond -->
    <link href="https://testingcf.jsdelivr.net/npm/filepond@4.30.4/dist/filepond.css" rel="stylesheet">
    <link href="https://testingcf.jsdelivr.net/npm/filepond-plugin-image-preview@4.6.12/dist/filepond-plugin-image-preview.css" rel="stylesheet">
    <script src="https://testingcf.jsdelivr.net/npm/filepond-plugin-image-preview@4.6.12/dist/filepond-plugin-image-preview.js"></script>
    <script src="https://testingcf.jsdelivr.net/npm/filepond@4.30.4/dist/filepond.js"></script>
    <style>
        .filepond--root { margin-bottom: 20px; }
        .filepond--panel-root { background-color: #f3f4f6; }
        .filepond--drop-label { color: #4b5563; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen p-8">
    <script>
        // FilePond 初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 注册插件
            FilePond.registerPlugin(FilePondPluginImagePreview);
            
            // 获取上传元素
            const inputElement = document.querySelector('input[type="file"].filepond');
            
            // 创建 FilePond 实例
            const pond = FilePond.create(inputElement, {
                allowMultiple: true,
                maxFiles: 10,
                instantUpload: true,
                server: {
                    url: './',
                    process: {
                        url: './',
                        method: 'POST',
                        withCredentials: false,
                        headers: {},
                        timeout: 700000,
                        onload: (response) => {
                            // 显示上传成功提示
                            const url = response;
                            showUploadSuccess(url);
                            return response;
                        },
                        onerror: (error) => {
                            console.error('上传错误:', error);
                            return error;
                        }
                    }
                },
                labelIdle: '拖放文件或 <span class="filepond--label-action">点击浏览</span>',
                labelFileProcessing: '上传中',
                labelFileProcessingComplete: '上传完成',
                labelTapToCancel: '点击取消',
                labelTapToRetry: '点击重试',
                labelTapToUndo: '点击撤销',
                labelButtonRemoveItem: '删除',
                labelButtonAbortItemLoad: '中止',
                labelButtonAbortItemProcessing: '取消',
                labelButtonUndoItemProcessing: '撤销',
                labelButtonRetryItemProcessing: '重试',
                labelButtonProcessItem: '上传',
                // 修复无障碍性问题
                dropLabelAccessibility: {
                    // 禁用 aria-hidden
                    disableAriaHidden: true
                },
                // 上传完成后自动移除文件
                onprocessfile: (error, file) => {
                    if (!error) {
                        // 延迟1秒后移除文件，让用户看到上传完成的状态
                        setTimeout(() => {
                            pond.removeFile(file.id);
                        }, 1000);
                    }
                }
            });
            
            // 显示上传成功提示
            function showUploadSuccess(url) {
                const successDiv = document.createElement('div');
                successDiv.className = 'bg-green-100 text-green-700 p-4 rounded-lg mb-6 flex justify-between items-center';
                successDiv.innerHTML = `
                    <div>
                        <span class="font-medium">上传成功！</span>
                        <span class="ml-2 text-sm">${url}</span>
                    </div>
                    <button class="text-green-700 hover:text-green-900" onclick="this.parentElement.remove()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                `;
                
                // 插入到上传区域后面
                const uploadArea = document.querySelector('.filepond--root').parentElement;
                uploadArea.parentNode.insertBefore(successDiv, uploadArea.nextSibling);
                
                // 5秒后自动消失
                setTimeout(() => {
                    if (successDiv.parentNode) {
                        successDiv.remove();
                    }
                }, 5000);
            }
        });
    </script>
    <div class="max-w-6xl mx-auto bg-white rounded-lg shadow-lg p-6">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Web User UI</h1>
            <div class="flex items-center space-x-4">
                <div class="text-sm text-gray-600">
                    <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full">
                        <?php echo htmlspecialchars($current_user['username']); ?> 
                        (<?php echo $current_user['role'] === 'admin' ? '管理员' : '普通用户'; ?>)
                    </span>
                </div>
                <div class="flex space-x-2">
                    <?php if ($current_user['role'] === 'admin'): ?>
                        <button onclick="showApiKeyManager()" 
                                class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded text-sm transition-colors">
                            API Key管理
                        </button>
                    <?php endif; ?>
                    <button onclick="showCurrentApiKey()" 
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm transition-colors">
                        查看我的API Key
                    </button>
                    <a href="?logout=1" 
                       class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-sm transition-colors">
                        退出登录
                    </a>
                </div>
            </div>
        </div>

        <!-- API Key管理模态框 -->
        <div id="apiKeyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800">API Key管理</h2>
                            <button onclick="hideApiKeyManager()" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="space-y-6">
                            <!-- 添加新用户 -->
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <h3 class="text-lg font-semibold mb-4">添加新用户</h3>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <input type="text" id="newUserId" placeholder="用户ID" 
                                           class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <input type="text" id="newUsername" placeholder="用户名" 
                                           class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <select id="newUserRole" 
                                            class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="user">普通用户</option>
                                        <option value="admin">管理员</option>
                                    </select>
                                </div>
                                <button onclick="addNewUser()" 
                                        class="mt-4 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded transition-colors">
                                    生成API Key
                                </button>
                            </div>
                            
                            <!-- 现有API Key列表 -->
                            <div>
                                <h3 class="text-lg font-semibold mb-4">现有API Key</h3>
                                <div id="apiKeyList" class="space-y-3">
                                    <?php if ($current_user['role'] === 'admin'): ?>
                                        <?php foreach ($configInstance->getAllApiKeys() as $key_id => $key_info): ?>
                                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                                <div class="flex justify-between items-start">
                                                    <div class="flex-1">
                                                        <div class="flex items-center space-x-2 mb-2">
                                                            <span class="font-medium"><?php echo htmlspecialchars($key_info['username']); ?></span>
                                                            <span class="text-xs px-2 py-1 rounded <?php echo $key_info['role'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                                <?php echo $key_info['role'] === 'admin' ? '管理员' : '普通用户'; ?>
                                                            </span>
                                                            <span class="text-xs px-2 py-1 rounded <?php echo $key_info['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                                <?php echo $key_info['status'] === 'active' ? '活跃' : '禁用'; ?>
                                                            </span>
                                                        </div>
                                                        <div class="text-sm text-gray-600 mb-2">
                                                            用户ID: <?php echo htmlspecialchars($key_info['user_id']); ?>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <input type="password" value="<?php echo htmlspecialchars($key_info['api_key']); ?>" 
                                                                   readonly class="flex-1 text-sm bg-gray-50 border border-gray-200 rounded px-3 py-2 font-mono">
                                                            <button onclick="toggleApiKeyVisibility(this)" 
                                                                    class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm">
                                                                显示
                                                            </button>
                                                            <button onclick="copyToClipboard('<?php echo htmlspecialchars($key_info['api_key']); ?>')" 
                                                                    class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm">
                                                                复制
                                                            </button>
                                                        </div>
                                                        <div class="text-xs text-gray-500 mt-2">
                                                            创建时间: <?php echo htmlspecialchars($key_info['created_at']); ?>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4">
                                                        <?php if ($key_info['status'] === 'active'): ?>
                                                            <button onclick="disableApiKey('<?php echo htmlspecialchars($key_info['api_key']); ?>')" 
                                                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                                                                禁用
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 当前用户API Key模态框 -->
        <div id="currentApiKeyModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-bold text-gray-800">我的API Key</h2>
                            <button onclick="hideCurrentApiKey()" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">用户信息</label>
                                <div class="bg-gray-50 p-3 rounded">
                                    <p><strong>用户名:</strong> <?php echo htmlspecialchars($current_user['username']); ?></p>
                                    <p><strong>用户ID:</strong> <?php echo htmlspecialchars($current_user['user_id']); ?></p>
                                    <p><strong>角色:</strong> <?php echo $current_user['role'] === 'admin' ? '管理员' : '普通用户'; ?></p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                                <div class="flex items-center space-x-2">
                                    <input type="password" value="<?php echo htmlspecialchars($_SESSION['api_key']); ?>" 
                                           readonly id="currentApiKeyInput"
                                           class="flex-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded font-mono text-sm">
                                    <button onclick="toggleCurrentApiKeyVisibility()" 
                                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm">
                                        显示
                                    </button>
                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['api_key']); ?>')" 
                                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm">
                                        复制
                                    </button>
                                </div>
                            </div>
                            
                            <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
                                <div class="flex">
                                    <svg class="w-5 h-5 text-yellow-400 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <div class="text-sm text-yellow-800">
                                        <p class="font-medium">安全提示：</p>
                                        <ul class="mt-1 list-disc list-inside space-y-1">
                                            <li>请妥善保管佬的API Key，不要泄露给他人</li>
                                            <li>API Key具有与佬账户相同的权限</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-8">
            <input type="file" class="filepond" name="file" multiple>
        </div>

        <?php if ($message): ?>
            <div class="<?php echo strpos($message, '成功') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> p-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="space-y-8">
            <!-- 图片区域 -->
            <div x-data="imageGallery()" class="space-y-4">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-semibold text-gray-700">图片文件</h2>
                    <div class="flex space-x-2">
                        <button @click="refreshImages" 
                                class="flex items-center space-x-2 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded transition-colors">
                            <svg class="w-4 h-4" :class="{'animate-spin': isLoading}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            <span>刷新列表</span>
                        </button>
                        <button onclick="copyAllImageUrls()" 
                                class="flex items-center space-x-2 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                            </svg>
                            <span>复制所有URL</span>
                        </button>
                    </div>
                </div>
                
                <!-- 图片列表 -->
                <div id="imageContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php foreach ($files_list as $file): ?>
                        <?php if ($file['type'] === 'image'): ?>
                            <div class="image-item bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="relative aspect-square">
                                    <img src="<?php echo htmlspecialchars($file['url']); ?>" 
                                         alt="<?php echo htmlspecialchars($file['name']); ?>"
                                         class="w-full h-full object-cover">
                                </div>
                                <div class="p-3">
                                    <div class="flex justify-between items-start mb-2">
                                        <div class="text-sm font-medium text-gray-900 truncate max-w-[80%]" title="<?php echo htmlspecialchars($file['name']); ?>">
                                            <?php echo htmlspecialchars($file['name']); ?>
                                        </div>
                                        <span class="text-xs px-2 py-1 rounded bg-blue-100 text-blue-800">
                                            <?php echo htmlspecialchars($file['extension']); ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 mb-2">
                                        <div>上传者: <?php echo htmlspecialchars($file['uploader']); ?></div>
                                        <div>时间: <?php echo htmlspecialchars($file['upload_time']); ?></div>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($file['url']); ?>')"
                                                class="flex-1 text-xs bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded">
                                            复制URL
                                        </button>
                                        <?php if ($file['can_manage']): ?>
                                            <button onclick="deleteImage('<?php echo htmlspecialchars($file['name']); ?>')"
                                                    class="text-xs bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded">
                                                删除
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
