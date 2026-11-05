# youvision/glide-server — 缩略图自动生成系统

基于 [league/glide](https://glide.thephpleague.com/) 构建的 URL 缩略图按需生成系统。一次部署，永久生效：上传原图后**无需生成任何缩略图**，前端直接通过 URL 参数实时裁剪缩放，首次访问自动生成并永久缓存。

```html
<img src="/uploads/news/111.jpg?w=300&h=400&fit=cover">
```

## 特性

- **零预生成** — 图片处理参数全部通过 URL 传递，前端随便组合尺寸
- **自动缓存** — 首次生成落盘，之后命中缓存直接返回（响应头 `X-Glide-Cache: HIT/MISS` 可观测）
- **浏览器友好** — `Cache-Control` + `Last-Modified` 304 协商缓存
- **安全内建** — 扩展名白名单、目录穿越校验、尺寸上限、固定尺寸模式
- **三驱动** — GD（默认）、Imagick 或 libvips
- **极简部署** — `composer create-project` 安装，上传网站根目录 + 一段 nginx 配置即用
- **可编程** — 核心类可复用：批量预热、URL 缓存清除等

## 目录结构

```
├── composer.json           系统清单（type: project，依赖 league/glide ^4.2）
├── thumb.php               入口脚本（nginx 把 /uploads/* 图片请求路由到这里）
├── config/config.php       系统配置
├── src/
│   ├── ThumbServer.php     核心类（请求解析 / 生成 / 输出）
│   └── RequestException.php
├── nginx.example.conf      nginx 配置示例
├── .htaccess               Apache 配置示例
├── router.php              PHP 内置服务器本地调试用
├── uploads/                原图目录（放入原始图片）
└── cache/glide/            缩略图缓存（自动创建，禁止外部访问）
```

## 环境要求

- PHP >= 8.3，扩展：`fileinfo`（默认 GD 驱动需 `gd`；可选 `imagick`、`exif`）
- nginx + php-fpm（或 Apache + mod_rewrite）

## 安装

```bash
# 已发布到 Packagist：
composer create-project youvision/glide-server my-site

# 未发布前，通过 VCS 仓库安装：
composer create-project youvision/glide-server my-site \
  --repository='{"type":"vcs","url":"https://github.com/youvision/glide-server.git"}'
```

## 部署

1. 将安装目录内容上传到网站根目录，如 `/var/www/site`
2. 编辑 `config/config.php`（见下方配置说明）
3. 把 `nginx.example.conf` 中 `server{}` 内的段落合并进站点配置，修改三处：

   | 配置 | 说明 |
   |---|---|
   | `root` | 网站根目录 |
   | `server_name` | 域名 |
   | `fastcgi_pass` | php-fpm 地址，如 `unix:/run/php/php8.2-fpm.sock` |

   然后重载配置：

   ```bash
   nginx -t && nginx -s reload
   ```

4. 确保缓存目录可写：

   ```bash
   mkdir -p uploads cache && chown -R www:www uploads cache
   ```

5. 访问测试：

   ```
   http://your-site.com/uploads/news/111.jpg?w=300&h=400&fit=cover
   ```

本地调试（无需 nginx）：

```bash
php -S localhost:8080 router.php
# 浏览器访问 http://localhost:8080/uploads/xxx.jpg?w=200&fit=cover
```

## 工作原理

nginx 将 `/uploads/` 下所有图片请求交给 `thumb.php`：

- **带缩放参数** → `ThumbServer` 校验请求 → 命中缓存直接返回，否则用 Glide 生成缩略图落盘后返回
- **不带参数** → 直接返回原图

请求 URL 保持原样，`<img src>` 想怎么写就怎么写。

## 配置说明（config/config.php）

| 键 | 默认值 | 说明 |
|---|---|---|
| `source_dir` | `uploads` | 原图目录（相对网站根目录，同时是 URL 前缀） |
| `cache_dir` | `cache/glide` | 缩略图缓存目录，必须禁止外部直接访问 |
| `driver` | `gd` | 图像驱动：`gd` / `imagick` / `vips` |
| `allowed_extensions` | jpg/jpeg/png/gif/webp | 源图扩展名白名单 |
| `max_width` / `max_height` | 4000 | 缩放尺寸上限，超限自动截断 |
| `max_image_size` | null | 源图像素总量上限（宽×高），原图超大建议设置 |
| `defaults` | `['q' => 85]` | 默认变换参数，URL 参数优先 |
| `preset_sizes_only` | `false` | 固定尺寸模式开关：开启后仅允许 `p=预设名` 生成预设尺寸 |
| `preset_sizes` | small/medium/large | 固定预设尺寸（键 = 预设名，值 = Glide 参数） |
| `group_cache_in_folders` | `true` | 缓存按源图分目录存放，URL 缓存清除依赖此项 |
| `cache_purge_param` | `del` | 缓存清除参数名；留空禁用 |
| `cache_purge_value` | `12345678` | 缓存清除触发值（口令），仅当参数值与此值完全一致时才删除 |
| `cache_ttl` | 31536000 | 浏览器端缓存有效期（秒） |

## URL 参数

| 参数 | 说明 | 示例 |
|---|---|---|
| `w` / `h` | 宽 / 高（px） | `w=300&h=400` |
| `fit` | 适配方式，可选：`contain`、`max`、`fill`、`fill-top/bottom/left/right`、`stretch`、`crop`、`crop-top/bottom/left/right`；已内置别名 `cover`→`crop` | `fit=cover` |
| `crop` | 预裁剪，格式 `x,y,w,h`（像素，先于缩放执行） | `crop=10,20,300,400` |
| `q` | 输出质量 0-100 | `q=85` |
| `dpr` | 倍屏输出（适配 Retina） | `dpr=2` |
| `bg` | 填充背景色 | `bg=ffffff` |
| `or` | 旋转角度 0/90/180/270（4.0 起由 `orient` 更名） | `or=90` |
| `blur` / `sharp` | 模糊 / 锐化 0-100 | `blur=5` |
| `fm` | 输出格式 jpg/pjpg/png/gif/webp/avif | `fm=webp` |
| `del` | 删除该图全部缓存（值须等于 `cache_purge_value`，见下文） | `del=12345678` |

完整参数列表：https://glide.thephpleague.com/

常用组合：

```
等比缩放（限宽）        /uploads/a.jpg?w=300
裁剪填充（封面）        /uploads/a.jpg?w=300&h=400&fit=cover
倍屏高清                /uploads/a.jpg?w=300&dpr=2
WebP 输出               /uploads/a.jpg?w=300&fm=webp
```

### 固定尺寸模式（preset_sizes_only）

在 `config/config.php` 中将 `'preset_sizes_only' => true` 开启后，系统只允许生成 `preset_sizes` 中定义的固定尺寸，任意 `w`/`h`/`fit` 参数将被拒绝（400），可有效防止恶意参数刷缓存：

```php
'preset_sizes_only' => true,
'preset_sizes' => [
    'small' => ['w' => 300, 'h' => 400, 'fit' => 'cover'],
    'medium' => ['w' => 800],
    'large' => ['w' => 1600],
],
```

调用方式改为预设名：

```
/uploads/news/111.jpg?p=small
/uploads/news/111.jpg?p=medium&fm=webp   # 输出格式仍可覆盖
```

开关关闭（默认）时，预设仍可作为 Glide `p` 参数使用，同时任意尺寸请求不受限。

## 安全机制

- **扩展名白名单** — 只处理白名单内的图片类型，勿放开到非图片类型
- **目录穿越防护** — `realpath` 校验，拦截 `../` 之类路径
- **尺寸上限** — `max_width` / `max_height` 强制截断，`max_image_size` 限制源图像素总量，防止 `w=99999` 之类的恶意参数
- **固定尺寸模式** — `preset_sizes_only` 开启后仅允许预设尺寸，杜绝任意参数
- **目录隔离** — nginx / Apache 示例已禁止外部访问 `cache/`、`vendor/`、`src/`、`config/`、隐藏文件

## 编程式调用

核心逻辑位于 `Youvision\GlideServer\ThumbServer`，可在自己的业务代码中复用：

```php
use Youvision\GlideServer\ThumbServer;

$thumbs = ThumbServer::create(require '/var/www/site/config/config.php');

// 生成/命中缩略图，返回缓存文件绝对路径（可用于预热脚本、消息队列）
$cached = $thumbs->makeImage('news/111.jpg', ['w' => 300, 'h' => 400, 'fit' => 'cover']);
```

预热全部文章封面示例：

```php
foreach ($sizes as [$w, $h]) {
    $thumbs->makeImage("news/{$id}.jpg", ['w' => $w, 'h' => $h, 'fit' => 'cover']);
}
```

### 删除缓存（del 参数）

URL 携带 `del` 参数（名称在 `cache_purge_param` 中配置）且**值与 `cache_purge_value` 完全一致**时，删除该图的全部缓存并返回 JSON 结果；值不匹配时按普通图片请求处理：

```bash
curl "https://example.com/uploads/news/111.jpg?del=12345678"
# {"path":"news/111.jpg","deleted":true}
```

清除后下次正常访问将重新生成缩略图。原图更新后可先清缓存，或用编程式 `$thumbs->glide()->deleteCache('news/111.jpg')`。

> 建议将 `cache_purge_value` 设置为随机长字符串（如 `'x7f9k2mq'`）作为删除口令，避免被恶意猜中后反复清缓存。也可将其设为空字符串配合 `cache_purge_param => ''` 完全禁用 URL 清除入口。

## 缓存说明

- 缓存键由「源图路径 + 全部变换参数」共同决定，参数不同缓存互不干扰
- 修改原图后需清除对应缓存（删除 `cache/glide/` 下对应文件，或整体清空），也可编程式清除：

  ```php
  $thumbs->glide()->deleteCache('news/111.jpg'); // 需保持默认 group_cache_in_folders
  ```
- 响应头带 `Cache-Control`、`Last-Modified`，浏览器走 304 协商缓存
- `X-Glide-Cache: HIT/MISS` 响应头可用于排查缓存命中情况

## 常见问题

**图片返回 404？** 检查原图是否存在于 `uploads/` 对应路径、扩展名是否在白名单内。

**返回 500 且日志出现 GD 报错？** 缩略图尺寸过大导致内存不足，调低 `max_width`/`max_height` 或增大 `memory_limit`，或改用 `imagick` 驱动。

**更换原图后不生效？** 清除 `cache/glide/` 下对应缓存文件（URL 加 `del` 参数，或编程式 `deleteCache()`）。

**想加水印？** 在 config 中配置 `watermarks` 目录后使用 `mark` 参数，详见 Glide 官方文档。

## License

MIT
