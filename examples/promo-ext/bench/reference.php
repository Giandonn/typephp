<?php

/**
 * 纯 PHP 参考实现：与 src/pricing.php（被 AOT 编译进 FPM）规则完全一致。
 * 用来独立复算期望值，和 FPM 返回结果逐字段比对。
 */

function reference_rules(): array
{
    return [
        'level_rate' => ['none' => 1.0, 'silver' => 0.95, 'gold' => 0.9],
        'thresholds' => [
            ['min' => 300.0, 'off' => 30.0],
            ['min' => 500.0, 'off' => 80.0],
        ],
        'coupons' => [
            'SAVE20' => ['type' => 'amount', 'value' => 20.0, 'min' => 200.0],
            'OFF10' => ['type' => 'percent', 'value' => 0.1, 'min' => 100.0],
        ],
        'free_shipping_amount' => 99.0,
    ];
}

function reference_quote(array $cart, array $context = []): array
{
    $rules = reference_rules();

    $subtotal = 0.0;
    $allDigital = true;
    foreach ($cart as $item) {
        $subtotal += (float)$item['price'] * (int)$item['qty'];
        if (($item['tag'] ?? 'physical') !== 'digital') {
            $allDigital = false;
        }
    }
    $subtotal = round($subtotal, 2);

    $rate = (float)($rules['level_rate'][$context['level'] ?? 'none'] ?? 1.0);
    $memberDiscount = round($subtotal * (1 - $rate), 2);

    $thresholdDiscount = 0.0;
    foreach ($rules['thresholds'] as $threshold) {
        if ($subtotal >= $threshold['min']) {
            $thresholdDiscount = (float)$threshold['off'];
        }
    }

    $coupon = $context['coupon'] ?? null;
    $couponDiscount = 0.0;
    $couponResult = null;
    if ($coupon !== null && $coupon !== '') {
        $rule = $rules['coupons'][$coupon] ?? null;
        if ($rule === null) {
            $couponResult = ['code' => $coupon, 'applied' => false, 'reason' => 'unknown_coupon'];
        } elseif ($subtotal < $rule['min']) {
            $couponResult = ['code' => $coupon, 'applied' => false, 'reason' => 'threshold_not_met'];
        } else {
            $couponDiscount = $rule['type'] === 'percent'
                ? round($subtotal * (float)$rule['value'], 2)
                : (float)$rule['value'];
            $couponResult = ['code' => $coupon, 'applied' => true];
        }
    }

    $shipping = (float)($context['shipping'] ?? 0.0);
    $payable = $subtotal - $memberDiscount - $thresholdDiscount - $couponDiscount;
    if ($allDigital || $payable >= (float)$rules['free_shipping_amount']) {
        $shipping = 0.0;
    }

    $total = round($payable + $shipping, 2);
    if ($total < 0) {
        $total = 0.0;
    }

    return [
        'version' => '1.0.0',
        'subtotal' => $subtotal,
        'memberDiscount' => $memberDiscount,
        'thresholdDiscount' => $thresholdDiscount,
        'couponDiscount' => $couponDiscount,
        'shipping' => $shipping,
        'total' => $total,
        'coupon' => $couponResult,
    ];
}

/**
 * 生成确定性的、互不相同的测试用例。
 *
 * @return array{0: array, 1: array}
 */
function reference_case(int $seed): array
{
    mt_srand($seed);

    $levels = ['none', 'silver', 'gold'];
    $coupons = [null, 'SAVE20', 'OFF10', 'FAKE'];

    $cart = [];
    $lines = mt_rand(1, 3);
    for ($i = 0; $i < $lines; $i++) {
        $cart[] = [
            'price' => mt_rand(10, 400) + mt_rand(0, 99) / 100,
            'qty' => mt_rand(1, 3),
            'tag' => mt_rand(0, 4) === 0 ? 'digital' : 'physical',
        ];
    }

    $context = [
        'level' => $levels[mt_rand(0, count($levels) - 1)],
        'coupon' => $coupons[mt_rand(0, count($coupons) - 1)],
        'shipping' => (float)mt_rand(0, 15),
    ];

    return [$cart, $context];
}
