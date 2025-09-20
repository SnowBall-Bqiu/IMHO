# IMHO - ~~极简~~一坨屎多用户图床系统  

## 技术栈
| 类别 | 库 / 组件 | 用途 |
|---|---|---|
| 前端 CSS | [Tailwind CSS 3.x (CDN)](https://tailwindcss.com) | 零配置、响应式界面 |
| 前端 JS | [Alpine.js 3.x (CDN)](https://alpinejs.dev) | 极简交互（Modal、刷新、复制） |
| 上传组件 | [FilePond 4.30](https://pqina.nl/filepond/) + ImagePreview 插件 | 拖拽多文件、实时预览 |
| 后端 | **原生 PHP ≥ 7.4** | 无框架依赖，单文件可跑 |
| 存储 | **本地文件系统** | 目录结构清晰，方便挂 CDN |
| 会话安全 | PHP 原生 Session + 严格 Cookie 参数 | 防固定、防 XSS、防 CSRF |

> 所有前端依赖均走 **jsDelivr CDN**，中国大陆已替换为 `testingcf.jsdelivr.net`，开箱即通。

---

## 目录结构
```
IMHO/
├─ i/                      ← 上传文件保存目录（自动生成）
├─ date/
│  ├─ logs/                ← 每日 API 日志（api_YYYY-MM-DD.log）
│  └─ records/
│     ├─ upload_records.json     ← 全站上传流水
│     ├─ user_files.json         ← 每个用户文件索引
│     └─ image_source_map.json   ← 图片溯源表
├─ index.php               ← Web 管理面板（含登录、上传、管理）
├─ api.php                 ← RESTful 上传接口
├─ config.php              ← 唯一配置中心（账号 / 域名 / 限额）
└─ README.md               ← 本文件
```

## 几分钟部署
###  下载
```bash
git clone https://github.com/XueQiu/IMHO.git
cd IMHO
chmod 755 -R .
```

###  一键配置
打开 `config.php`，仅需改 4 处：
```php
            // 用户API Key映射 - 每个用户拥有独立的API Key
            'user_api_keys' => [
                // 管理员用户
                'admin_key_001' => [
                    'user_id' => 'admin', 
                    'username' => 'admin', //用户昵称（也是文件的前缀）
                    'role' => 'admin',    // 用户权限 admin/user
                    'api_key' => 'ky-admin-123',  //这里填写你的Key，到时候API调用也用得到
                    'created_at' => '2025-06-15',
                    'status' => 'active'
                ]
            ],
            
            // 文件存储配置
            'upload_dir' => 'i/',  //存储的目录
            'base_url' => 'https://***.*/i/', //将***.*替换为你的域名
			'max_file_size' => 50 * 1024 * 1024,          //  单文件最大 50 MB
	// 返回URL映射（兼容原API）
    'return_url_map' => [
    '1' => 'https://***.*'  //将***.*替换为你的域名，同时你可以添加多个，'1'为你指定的返回的域名序号
            ]

```

###  完成
浏览器访问 `https://your-domain.com/index.php`  
输入config配置的 API Key → 登录成功 → 拖拽一张图 → 拿到url，收工！

### 接入PicGO
#### 下载自定义web图床插件
<img width="450" height="134" alt="image" src="https://github.com/user-attachments/assets/c916b4ac-a386-4c32-a1aa-185501ca7cd8" />

#### 示例配置如图
<img width="885" height="619" alt="image" src="https://github.com/user-attachments/assets/a0e4f730-bd1f-4932-95f4-9d10c942917c" />




---

## API 文档
> Base URL: `https://your-domain.com/api.php`

###  获取 Token （和panel登录的是一个）
登录 Web 面板 → 右上角「查看我的API Key」→ 复制（仅自己可见）。

###  上传
```bash
curl -X POST \
  -H "X-Auth-Key: ky-admin-123" \
  -F "file=@/path/to/pic.jpg" \
  -F "url=2" \
  -F "base_url=https://cdn.example.com/i/" \
  https://your-domain.com/api.php
```

| 参数 | 说明 | 默认值 |
|---|---|---|
| `file` | 二进制文件（必填） | — |
| `url` | 回源编号（见 `return_url_map`） | `2` |
| `base_url` | 自定义外链域名（优先级高于 `url`） | 无 |

###  返回示例
```json
{
  "success": true,
  "message": "上传成功",
  "data": {
    "url": "https://cdn.example.com/i/admin_20250920_img_a3f9e7b8.jpg",
    "original_url": "https://your-domain.com/i/admin_20250920_img_a3f9e7b8.jpg",
    "filename": "admin_20250920_img_a3f9e7b8.jpg",
    "size": 238784
  }
}
```

###  错误码
| HTTP | 含义 |
|---|---|
| 401 | API Key 无效 / 被禁用 |
| 400 | 文件超限 / 类型不合 |
| 405 | 非 POST 请求 |
| 500 | 服务器内部错误 |

---

## . ~~管理员常用操作~~ 暂未实现
| 功能 | 入口 | 说明 |
|---|---|---|
| 新增普通用户 | Web 面板 → API Key管理 → 填写 ID+用户名 → 生成 | 自动返回 Key |
| 禁用 Key | 同上 → 点击「禁用」 | 即时生效，已登录会话立即踢出 |
| 查看全站日志 | `date/logs/api_YYYY-MM-DD.log` | 一行一条 JSON，方便 `grep` |
| 迁移存储 | 直接 `rsync -av i/ 新服务器:/path/i/` → 改 `base_url` | 零停机 |

---
