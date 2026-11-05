<?php

declare(strict_types=1);

/*
 * 缩略图自动生成系统入口
 *
 * 由 nginx / apache 把 /uploads/ 下的图片请求路由到本脚本，
 * 处理形如 /uploads/news/111.jpg?w=300&h=400&fit=cover 的请求：
 *   - 带缩放参数：生成缩略图并缓存，之后命中缓存直接返回
 *   - 不带参数：  直接返回原图
 */

require __DIR__ . '/vendor/autoload.php';

use Youvision\GlideServer\ThumbServer;

ThumbServer::create(
    require __DIR__ . '/config/config.php'
)->serve();
