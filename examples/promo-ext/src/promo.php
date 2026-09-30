<?php

const PROMO_VERSION = '1.0.0';

/**
 * 内置优惠规则。规则保持稳定，只把可变参数（等级、券码、运费）交给宿主传入。
 */
function promo_rules(): array
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

/**
 * 计算购物车优惠与应付金额。
 *
 * @param array $cart    [['price' => float, 'qty' => int, 'tag' => 'digital'|'physical'], ...]
 * @param array $context ['level' => string, 'coupon' => string|null, 'shipping' => float]
 */
function promo_quote(array $cart, array $context = []): array
{
    $rules = promo_rules();

    // 1. 统计金额，并判断是否全部是可下载商品
    $subtotal = 0.0;
    $allDigital = true;
    foreach ($cart as $item) {
        $subtotal += (float)$item['price'] * (int)$item['qty'];
        if (($item['tag'] ?? 'physical') !== 'digital') {
            $allDigital = false;
        }
    }
    $subtotal = round($subtotal, 2);

    // 2. 会员折扣
    $rate = (float)($rules['level_rate'][$context['level'] ?? 'none'] ?? 1.0);
    $memberDiscount = round($subtotal * (1 - $rate), 2);

    // 3. 满减：命中最高一档
    $thresholdDiscount = 0.0;
    foreach ($rules['thresholds'] as $threshold) {
        if ($subtotal >= $threshold['min']) {
            $thresholdDiscount = (float)$threshold['off'];
        }
    }

    // 4. 优惠券
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

    // 5. 运费：数字商品不计运费；实付满 99 免运费
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
        'version' => PROMO_VERSION,
        'subtotal' => $subtotal,
        'memberDiscount' => $memberDiscount,
        'thresholdDiscount' => $thresholdDiscount,
        'couponDiscount' => $couponDiscount,
        'shipping' => $shipping,
        'total' => $total,
        'coupon' => $couponResult,
    ];
}
