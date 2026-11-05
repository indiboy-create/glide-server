<?php

declare(strict_types=1);

namespace Youvision\GlideServer;

use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Server;
use League\Glide\ServerFactory;

/**
 * 基于 league/glide 的 URL 缩略图按需生成服务。
 *
 * 由 nginx/apache 把 /uploads/ 下的图片请求路由到一个薄入口脚本，
 * 处理形如 /uploads/news/111.jpg?w=300&h=400&fit=cover 的请求：
 *   - 带变换参数：生成缩略图并落盘缓存，之后命中缓存直接返回
 *   - 不带参数：  直接返回原图
 *
 * 也可编程式调用：makeImage() 批量预热等
 */
class ThumbServer
{
    protected const DEFAULT_CONFIG = [
        // 网站根目录；留空则使用 getcwd()
        'web_root' => '',
        // 原图目录（相对 web_root，同时是 URL 前缀）
        'source_dir' => 'uploads',
        // 缩略图缓存目录（相对 web_root），须禁止外部直接访问
        'cache_dir' => 'cache/glide',
        // 缓存按源图分目录存放（glide 默认 true）；deleteCache 缓存清除依赖此项
        'group_cache_in_folders' => true,
        // 图像处理驱动：gd、imagick 或 vips（league/glide 4.x）
        'driver' => 'gd',
        // 允许处理的源图扩展名
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        // 缩放尺寸上限，防止恶意超大参数耗尽内存
        'max_width' => 4000,
        'max_height' => 4000,
        // 源图像素总量上限（宽×高），null 表示不限制
        'max_image_size' => null,
        // Glide 默认变换参数（URL 参数优先级更高）
        'defaults' => ['q' => 85],
        // 水印开关：开启后 mark 参数生效
        'watermarks_enabled' => false,
        // 水印文件目录（相对 web_root），URL 通过 mark=文件名 指定水印
        'watermarks' => 'watermarks',
        // 默认水印参数（URL 参数优先级更高），如 ['mark' => 'logo.png', 'markw' => 200]
        'watermark_defaults' => [],
        // 仅允许生成预设尺寸：开启后 URL 只能通过 p=预设名 指定尺寸，
        // w/h/fit 等其他参数将被忽略（fm 输出格式仍允许覆盖）
        'preset_sizes_only' => false,
        // 固定预设尺寸（键 = 预设名，值 = Glide 变换参数）
        'preset_sizes' => [
            'small' => ['w' => 300, 'h' => 400, 'fit' => 'cover'],
            'medium' => ['w' => 800],
            'large' => ['w' => 1600],
        ],
        // 浏览器端缓存有效期（秒）
        'cache_ttl' => 31536000,
        // fit 参数别名：兼容 CSS / Intervention 风格写法
        'fit_aliases' => [
            'cover' => 'crop',
            'contain' => 'contain',
            'stretch' => 'stretch',
            'fill' => 'fill',
        ],
        // 缓存清除参数名：URL 携带该参数且值等于 cache_purge_value 时删除该图全部缓存
        // 该值即删除操作的口令，建议设置为随机长字符串；留空禁用
        'cache_purge_param' => 'del',
        // 缓存清除触发值：仅当 del 参数值与该值一致时才删除缓存
        'cache_purge_value' => '12345678',
    ];

    protected array $config;
    protected ?Server $glide = null;
    protected ?string $sourceRoot = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);

        if ($this->config['web_root'] === '' || $this->config['web_root'] === null) {
            $this->config['web_root'] = getcwd();
        }
        $this->config['web_root'] = rtrim((string) $this->config['web_root'], '/\\');
    }

    /** 便捷构造 */
    public static function create(array $config = []): static
    {
        return new static($config);
    }

    /** 读取合并后的配置 */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    /* ================================================================
     * HTTP 请求处理
     * ================================================================ */

    /**
     * 处理当前 HTTP 请求并输出结果，随后终止脚本。
     *
     * @param string|null $uri 请求 URI（含查询串），默认取 $_SERVER['REQUEST_URI']
     */
    public function serve(?string $uri = null): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $this->emitError(405, 'Method Not Allowed');
        }

        try {
            [$relative, $rawQuery, $ifModifiedSince] = $this->parseRequest($uri);

            // 缓存清除请求：del 参数值与 cache_purge_value 一致时，删除该图全部缓存
            $purgeParam = trim((string) $this->config['cache_purge_param']);
            $purgeValue = (string) $this->config['cache_purge_value'];
            if (
                $purgeParam !== ''
                && isset($rawQuery[$purgeParam])
                && (string) $rawQuery[$purgeParam] === $purgeValue
            ) {
                $this->purgeCache($relative);
            }

            $params = $this->sanitizeParams($rawQuery);

            if ($params === []) {
                // 无变换参数：直接返回原图
                $this->emitFile($this->sourceFile($relative), (int) $this->config['cache_ttl'], false, $ifModifiedSince, $method);
            }

            $cached = $this->generate($relative, $params);
            $this->emitFile($cached, (int) $this->config['cache_ttl'], true, $ifModifiedSince, $method);
        } catch (RequestException $e) {
            $this->emitError($e->getStatusCode(), $e->getMessage());
        } catch (FileNotFoundException $e) {
            $this->emitError(404, 'Image Not Found');
        } catch (\League\Flysystem\UnableToReadFile $e) {
            $this->emitError(404, 'Image Not Found');
        } catch (\InvalidArgumentException $e) {
            $this->emitError(400, 'Invalid image parameters: ' . $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[glide-thumbnail] ' . $e->getMessage());
            $this->emitError(500, 'Image processing failed');
        }

        exit;
    }

    /**
     * 解析请求 URI：校验前缀/扩展名/目录穿越，返回
     * [相对路径, 原始查询参数, If-Modified-Since 头]。
     */
    protected function parseRequest(?string $uri): array
    {
        $uri ??= $_SERVER['REQUEST_URI'] ?? '/';
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $prefix = '/' . trim((string) $this->config['source_dir'], '/');

        if (strpos($path, $prefix . '/') !== 0) {
            throw new RequestException(404, 'Not Found');
        }

        $relative = ltrim(substr($path, strlen($prefix)), '/');

        // 扩展名白名单
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, (array) $this->config['allowed_extensions'], true)) {
            throw new RequestException(404, 'Unsupported file type');
        }

        // 目录穿越防护
        $root = $this->resolveSourceRoot();
        $real = realpath($root . '/' . $relative);
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RequestException(404, 'Image Not Found');
        }

        $query = $_GET;

        return [$relative, $query, $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''];
    }

    /**
     * 参数清洗：预设尺寸校验、映射 fit 别名、强制尺寸上限。
     * 返回空数组表示无变换参数（即直接返回原图）。
     */
    protected function sanitizeParams(array $query): array
    {
        $presets = (array) $this->config['preset_sizes'];

        // 开启预设开关：仅允许 p=预设名，忽略其他尺寸参数
        if (!empty($this->config['preset_sizes_only'])) {
            $name = (string) ($query['p'] ?? '');
            if (!isset($presets[$name])) {
                throw new RequestException(
                    400,
                    'Missing or invalid preset "p". Allowed presets: ' . implode(', ', array_keys($presets))
                );
            }

            $params = (array) $presets[$name];
            if (isset($query['fm'])) {
                $params['fm'] = $query['fm']; // 输出格式仍允许覆盖
            }

            return $params;
        }

        $aliases = (array) $this->config['fit_aliases'];
        if (isset($query['fit']) && is_string($query['fit']) && isset($aliases[strtolower($query['fit'])])) {
            $query['fit'] = $aliases[strtolower($query['fit'])];
        }

        foreach (['w' => 'max_width', 'h' => 'max_height'] as $key => $limit) {
            if (isset($query[$key]) && (int) $query[$key] > (int) $this->config[$limit]) {
                $query[$key] = (int) $this->config[$limit];
            }
        }

        return $query;
    }

    /**
     * 删除指定图片的全部缓存并输出 JSON 结果，随后终止脚本。
     * 特权操作：del 参数值必须与 cache_purge_value 完全一致（已在 serve() 中校验），
     * 建议将 cache_purge_value 设置为随机长字符串作为口令。
     */
    protected function purgeCache(string $relative): void
    {
        if ((bool) $this->config['group_cache_in_folders'] === false) {
            throw new RequestException(500, 'Cache purge requires "group_cache_in_folders" to be enabled.');
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'path' => $relative,
            'deleted' => (bool) $this->glide()->deleteCache($relative),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* ================================================================
     * 输出
     * ================================================================ */

    /** 输出文件（含缓存头与 304 协商），随后终止脚本 */
    protected function emitFile(string $file, int $ttl, bool $hitCache, string $ifModifiedSince, string $method = 'GET'): void
    {
        if (!is_file($file)) {
            throw new RequestException(404, 'Image Not Found');
        }

        $mtime = (int) filemtime($file);
        header('Cache-Control: public, max-age=' . $ttl);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Glide-Cache: ' . ($hitCache ? 'HIT' : 'MISS'));

        // 协商缓存：未变更时返回 304
        if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) === $mtime) {
            http_response_code(304);
            exit;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));

        if ($method !== 'HEAD') {
            readfile($file);
        }
        exit;
    }

    /** 输出错误响应，随后终止脚本 */
    protected function emitError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit($message);
    }

    /* ================================================================
     * 编程式 API
     * ================================================================ */

    /**
     * 生成（或命中）缩略图，返回缓存文件绝对路径。
     * 入参会先经过参数清洗（fit 别名 / 尺寸上限 / 预设尺寸校验）。
     *
     * @param string $relative 相对 source_dir 的路径，如 news/111.jpg
     */
    public function makeImage(string $relative, array $params = []): string
    {
        return $this->generate($relative, $this->sanitizeParams($params));
    }

    /** 内部生成流程：跳过参数清洗，避免 serve() 已清洗的参数被重复处理 */
    protected function generate(string $relative, array $params): string
    {
        $cached = $this->glide()->makeImage($relative, $params);

        // glide 4.x 的 makeImage 返回缓存文件系统内的路径，
        // 本地 Filesystem 下可能为相对路径，统一解析为绝对路径
        if (is_file($cached)) {
            return $cached;
        }

        return $this->cacheRoot() . '/' . ltrim(str_replace('\\', '/', $cached), '/');
    }

    /** 缓存目录绝对路径 */
    public function cacheRoot(): string
    {
        return $this->config['web_root'] . '/' . trim((string) $this->config['cache_dir'], '/');
    }

    /** 源图绝对路径 */
    public function sourceFile(string $relative): string
    {
        return $this->resolveSourceRoot() . '/' . ltrim($relative, '/\\');
    }

    /** 惰性创建 Glide Server */
    public function glide(): Server
    {
        if ($this->glide === null) {
            $cacheRoot = $this->cacheRoot();
            if (!is_dir($cacheRoot) && !@mkdir($cacheRoot, 0775, true) && !is_dir($cacheRoot)) {
                throw new RequestException(500, 'Cannot create cache directory');
            }

            $settings = [
                'source' => $this->resolveSourceRoot(),
                'cache' => $cacheRoot,
                'driver' => $this->config['driver'],
                'defaults' => (array) $this->config['defaults'],
                'group_cache_in_folders' => (bool) $this->config['group_cache_in_folders'],
            ];

            if (!empty($this->config['watermarks_enabled'])) {
                $settings['watermarks'] = $this->config['web_root'] . '/' . trim((string) $this->config['watermarks'], '/');
                // 水印默认参数合并进 defaults，URL 参数仍优先
                $settings['defaults'] = array_merge((array) $this->config['watermark_defaults'], $settings['defaults']);
            }

            if ((array) $this->config['preset_sizes'] !== []) {
                $settings['presets'] = (array) $this->config['preset_sizes'];
            }

            if (!empty($this->config['max_image_size'])) {
                $settings['max_image_size'] = (int) $this->config['max_image_size'];
            }

            $this->glide = ServerFactory::create($settings);
        }

        return $this->glide;
    }

    protected function resolveSourceRoot(): string
    {
        if ($this->sourceRoot === null) {
            $root = realpath($this->config['web_root'] . '/' . trim((string) $this->config['source_dir'], '/'));
            if ($root === false) {
                throw new RequestException(500, 'Source directory missing');
            }
            $this->sourceRoot = $root;
        }

        return $this->sourceRoot;
    }
}
