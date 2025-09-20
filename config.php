<?php
// 图床系统统一配置文件

/**
 * 统一配置访问类
 */
class ImageHostConfig {
    private static $instance = null;
    private $config = [];
    
    private function __construct() {
        $this->initConfig();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initConfig() {
        $this->config = [
            
            // 用户API Key映射 - 每个用户拥有独立的API Key
            'user_api_keys' => [
                // 管理员用户
                'admin_key_001' => [
                    'user_id' => 'admin',
                    'username' => 'admin',
                    'role' => 'admin',
                    'api_key' => 'ky-admin-123',
                    'created_at' => '2025-06-15',
                    'status' => 'active'
                ]
            ],
            
            // 文件存储配置
            'upload_dir' => 'i/',
            'base_url' => 'https://***.*/i/',
            
            // 支持的文件类型
            'supported_types' => [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'svg', 'ico'],
                // 'audio' => ['mp3'],  // 已禁用音频上传
                // 'video' => ['mp4', 'webm']  // 已禁用视频上传
            ],
            
            // 文件大小限制（字节）
            'max_file_size' => 50 * 1024 * 1024, // 50MB
            
            // 日志配置
            'log_dir' => __DIR__ . '/date',
            'log_retention_days' => 90,
            
            // JSON记录配置
            'json_records_dir' => __DIR__ . '/date/records',
            'json_records_file' => __DIR__ . '/date/records/upload_records.json',
            'user_files_record' => __DIR__ . '/date/records/user_files.json',
            'image_source_map' => __DIR__ . '/date/records/image_source_map.json',
            
            // 返回URL映射（兼容原API）
            'return_url_map' => [
                '1' => 'https://***.*'
            ]
        ];
        
        $this->ensureDirectories();
    }
    
    /**
     * 获取配置项
     */
    public function get($key, $default = null) {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }
    
    /**
     * 设置配置项
     */
    public function set($key, $value) {
        $this->config[$key] = $value;
    }
    
    /**
     * 获取所有配置
     */
    public function getAll() {
        return $this->config;
    }
    
    /**
     * 根据API Key获取用户信息
     */
    public function getUserByApiKey($api_key) {
        $user_api_keys = $this->get('user_api_keys', []);
        foreach ($user_api_keys as $key_info) {
            if ($key_info['api_key'] === $api_key && $key_info['status'] === 'active') {
                return $key_info;
            }
        }
        return null;
    }
    
    /**
     * 生成新的API Key
     */
    public function generateApiKey($prefix = 'ky') {
        return $prefix . '-' . bin2hex(random_bytes(32));
    }
    
    /**
     * 添加新用户API Key
     */
    public function addUserApiKey($user_id, $username, $role = 'user') {
        $user_api_keys = $this->get('user_api_keys', []);
        $api_key = $this->generateApiKey($role === 'admin' ? 'ky-admin' : 'ky-user');
        
        $key_id = $role . '_key_' . uniqid();
        $user_api_keys[$key_id] = [
            'user_id' => $user_id,
            'username' => $username,
            'role' => $role,
            'api_key' => $api_key,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'active'
        ];
        
        $this->set('user_api_keys', $user_api_keys);
        $this->saveConfig();
        
        return $api_key;
    }
    
    /**
     * 禁用API Key
     */
    public function disableApiKey($api_key) {
        $user_api_keys = $this->get('user_api_keys', []);
        foreach ($user_api_keys as $key_id => &$key_info) {
            if ($key_info['api_key'] === $api_key) {
                $key_info['status'] = 'disabled';
                $key_info['disabled_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        $this->set('user_api_keys', $user_api_keys);
        $this->saveConfig();
    }
    
    /**
     * 获取所有API Key（仅管理员可用）
     */
    public function getAllApiKeys() {
        return $this->get('user_api_keys', []);
    }
    
    /**
     * 保存配置到文件
     */
    private function saveConfig() {
        // 这里可以实现配置持久化，当前版本使用内存存储
        // 在实际应用中，可以将配置保存到数据库或配置文件
    }
    
    /**
     * 图片溯源：根据文件名解析上传来源
     */
    public function parseImageSource($filename) {
        // 解析文件名格式：{user_id}_{timestamp}_{random}.{ext}
        $parts = explode('_', $filename);
        if (count($parts) >= 3) {
            $user_id = $parts[0];
            $timestamp = (int)$parts[1]; // 确保转换为整数类型
            
            // 验证时间戳是否有效
            if ($timestamp > 0) {
                return [
                    'user_id' => $user_id,
                    'upload_time' => date('Y-m-d H:i:s', $timestamp),
                    'timestamp' => $timestamp,
                    'filename' => $filename
                ];
            }
        }
        
        return [
            'user_id' => 'unknown',
            'upload_time' => 'unknown',
            'timestamp' => 0,
            'filename' => $filename
        ];
    }
    
    /**
     * 保存图片来源映射
     */
    public function saveImageSourceMap($filename, $user_info, $upload_method = 'web') {
        $source_map_file = $this->get('image_source_map');
        
        // 读取现有映射
        $source_map = [];
        if (file_exists($source_map_file)) {
            $json_content = file_get_contents($source_map_file);
            $existing_map = json_decode($json_content, true);
            if (is_array($existing_map)) {
                $source_map = $existing_map;
            }
        }
        
        // 添加新映射
        $source_map[$filename] = [
            'user_id' => $user_info['user_id'],
            'username' => $user_info['username'],
            'role' => $user_info['role'],
            'upload_time' => date('Y-m-d H:i:s'),
            'upload_method' => $upload_method, // 'web', 'api', 'legacy_api'
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        // 保存映射
        $json_data = json_encode($source_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($source_map_file, $json_data, LOCK_EX);
    }
    
    /**
     * 获取图片来源信息
     */
    public function getImageSource($filename) {
        $source_map_file = $this->get('image_source_map');
        
        if (file_exists($source_map_file)) {
            $json_content = file_get_contents($source_map_file);
            $source_map = json_decode($json_content, true);
            
            if (is_array($source_map) && isset($source_map[$filename])) {
                return $source_map[$filename];
            }
        }
        
        // 如果映射文件中没有，尝试从文件名解析
        return $this->parseImageSource($filename);
    }
    
    /**
     * 确保必要目录存在
     */
    private function ensureDirectories() {
        $directories = [
            $this->config['log_dir'],
            $this->config['json_records_dir'],
            $this->config['upload_dir']
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}

// 创建全局配置实例
$configInstance = ImageHostConfig::getInstance();

// 为了向后兼容，返回配置数组
$config = $configInstance->getAll();

return $config;
?>
