<?php

/*
 * PHP 内置服务器路由文件，仅用于本地调试：
 *   php -S localhost:8080 router.php
 */

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// 图片请求交给系统入口
if (preg_match('#^/uploads/.+\.(jpg|jpeg|png|gif|webp)$#i', $uri)) {
    require __DIR__ . '/thumb.php';
    return true;
}

// 其他真实存在的静态文件直接返回（realpath 校验，拒绝 ../ 穿越）
$file = $uri !== '/' ? realpath(__DIR__ . $uri) : false;
if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require __DIR__ . '/thumb.php';
return true;
