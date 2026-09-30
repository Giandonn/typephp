<?php

/**
 * 请求脚本：由 php-fpm 按普通 docroot 方式执行，不参与 AOT 编译。
 * 业务逻辑 promo_quote() 由 AOT 模块提供（见 src/pricing.php）。
 */

header('Content-Type: application/json');

$id = (string)($_GET['id'] ?? '');
$cart = json_decode((string)($_GET['cart'] ?? ''), true);

if (!is_array($cart)) {
    http_response_code(400);
    echo json_encode(['id' => $id, 'error' => 'bad_cart']);
    return;
}

$coupon = (string)($_GET['coupon'] ?? '');

$context = [
    'level' => (string)($_GET['level'] ?? 'none'),
    'coupon' => $coupon === '' ? null : $coupon,
    'shipping' => (float)($_GET['ship'] ?? 0.0),
];

echo json_encode([
    'id' => $id,
    'pid' => getmypid(),
    'seq' => promo_probe_seq(),
    'quote' => promo_quote($cart, $context),
]);
