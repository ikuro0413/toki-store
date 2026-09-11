<?php
/**
 * PHPが動くことと、非公開ディレクトリが読めることの確認用。
 * 確認が済んだら削除する。秘密の値は一切出さない。
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$privateDir = dirname(__DIR__, 3) . '/private';

echo json_encode([
    'ok'             => true,
    'php'            => PHP_VERSION,
    'curl'           => function_exists('curl_init'),
    'hash_hmac'      => function_exists('hash_hmac'),
    'doc_root'       => $_SERVER['DOCUMENT_ROOT'] ?? '',
    'api_dir'        => __DIR__,
    'private_guess'  => $privateDir,
    'private_found'  => is_dir($privateDir),
    'env_found'      => is_readable($privateDir . '/toki-env.php'),
    'products_found' => is_readable($privateDir . '/products.json'),
    'writable'       => is_dir($privateDir) ? is_writable($privateDir) : false,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
