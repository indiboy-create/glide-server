<?php

/**
 * 缩略图系统配置文件
 */

return [
    // 网站根目录（thumb.php 所在目录），一般无需修改
    'web_root' => dirname(__DIR__),

    // 原图目录（相对网站根目录，同时是 URL 前缀），如 /uploads/news/111.jpg
    'source_dir' => 'uploads',

    // 缩略图缓存目录（相对网站根目录），nginx 中必须禁止外部直接访问
    'cache_dir' => 'cache/glide',

    // 缓存按源图分目录存放；URL 缓存清除（del 参数）依赖此项，保持 true
    'group_cache_in_folders' => true,

    // 图像处理驱动：gd、imagick 或 vips（league/glide 4.x）
    'driver' => 'gd',

    // 允许处理的源图扩展名
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

    // 缩放尺寸上限，防止恶意超大参数耗尽内存
    'max_width' => 4000,
    'max_height' => 4000,

    // 源图像素总量上限（宽×高），null 表示不限制；原图超大时建议设置，如 30000000
    'max_image_size' => null,

    // 默认参数（URL 参数优先级更高）
    'defaults' => [
        'q' => 85, // 默认输出质量
    ],

    // ===== 水印 =====
    // 开关：是否启用水印功能；开启后 mark 参数生效，watermarks 目录才会传给 Glide
    'watermarks_enabled' => false,

    // 水印文件目录（相对网站根目录），目录下存放可作为水印使用的图片
    // 启用后 URL 通过 mark=文件名（相对该目录）指定水印，如 ?mark=logo.png&markw=200
    'watermarks' => 'watermarks',

    // 默认水印参数（URL 参数优先级更高）；设置 mark 即为全站默认水印
    // 常用：mark=logo.png、markw=200、markalpha=80、markpos=bottom-right
    'watermark_defaults' => [],

    // ===== 固定尺寸模式 =====
    // 开关：true = 仅允许生成下方 preset_sizes 中定义的固定尺寸
    // 开启后 URL 只能通过 p=预设名 指定尺寸（如 ?p=small），
    // w/h/fit 等其他尺寸参数将被忽略并返回 400
    'preset_sizes_only' => false,

    // 固定预设尺寸（键 = 预设名，值 = Glide 变换参数，按需增删）
    'preset_sizes' => [
        'small' => ['w' => 300, 'h' => 400, 'fit' => 'cover'],
        'medium' => ['w' => 800],
        'large' => ['w' => 1600],
    ],

    // 浏览器端缓存有效期（秒），用于 Cache-Control / 304 协商
    'cache_ttl' => 31536000,

    // 缓存清除参数名：URL 携带该参数且值等于 cache_purge_value 时删除该图全部缓存
    // 该值即删除操作的口令，建议设置为随机长字符串；留空禁用
    'cache_purge_param' => 'del',

    // 缓存清除触发值：仅当 del 参数值与该值完全一致时才执行删除
    'cache_purge_value' => '12345678',
];
