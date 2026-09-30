<?php
/**
 * 宿主 PHP 项目调用示例：只依赖扩展，不加载任何 PHP 源码。
 *
 *   php -d extension=/path/to/promo.so demo.php
 */

function money(float $value): string
{
    return number_format($value, 2);
}

function show(string $title, array $cart, array $context): void
{
    $r = promo_quote($cart, $context);
    printf("%s\n", $title);
    printf(
        "  小计 %s  会员 -%s  满减 -%s  券 -%s  运费 +%s  =>  应付 %s\n",
        money($r['subtotal']),
        money($r['memberDiscount']),
        money($r['thresholdDiscount']),
        money($r['couponDiscount']),
        money($r['shipping']),
        money($r['total'])
    );
    if ($r['coupon'] !== null && $r['coupon']['applied'] === false) {
        printf("  券 %s 未生效：%s\n", $r['coupon']['code'], $r['coupon']['reason']);
    }
}

printf("扩展 typephp_promo：%s，版本 %s\n\n", extension_loaded('typephp_promo') ? '已加载' : '未加载', PROMO_VERSION);

$bigCart = [
    ['price' => 299.0, 'qty' => 1, 'tag' => 'physical'],
    ['price' => 120.0, 'qty' => 2, 'tag' => 'physical'],
];

show('A. 黄金会员 + SAVE20', $bigCart, ['level' => 'gold', 'coupon' => 'SAVE20', 'shipping' => 12.0]);
show('B. 白银会员 + OFF10', $bigCart, ['level' => 'silver', 'coupon' => 'OFF10', 'shipping' => 12.0]);
show('C. 普通会员 / 小车 / 收运费', [['price' => 45.0, 'qty' => 1, 'tag' => 'physical']], ['level' => 'none', 'shipping' => 12.0]);
show('D. 数字商品 / 不收运费', [['price' => 45.0, 'qty' => 1, 'tag' => 'digital']], ['level' => 'none', 'shipping' => 12.0]);
show('E. SAVE20 未满 200 门槛', [['price' => 120.0, 'qty' => 1, 'tag' => 'physical']], ['level' => 'none', 'coupon' => 'SAVE20', 'shipping' => 12.0]);
show('F. 不存在的券码', $bigCart, ['level' => 'gold', 'coupon' => 'FAKE', 'shipping' => 12.0]);
