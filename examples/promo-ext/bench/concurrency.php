<?php

/**
 * 并发正确性校验器。
 *
 * 用法：
 *   php bench/concurrency.php [base_url] [并发级别,逗号分隔] [每轮请求数] [用例数] [随机种子]
 *
 * 例：
 *   php bench/concurrency.php http://127.0.0.1:18080/index.php 1,8,32,64 200 32
 *
 * 每个请求携带唯一 id 与确定性的购物车参数；校验：
 *   1. id 回显一致          → 请求之间没有串扰
 *   2. seq 恒为 1           → 请求级静态状态被正确重置
 *   3. 结果与纯 PHP 参考一致 → 业务结果正确
 * 同一批用例会被重复请求多次，且分散在不同 worker 上，用于暴露跨请求状态泄漏。
 */

require __DIR__ . '/reference.php';

$baseUrl = $argv[1] ?? 'http://127.0.0.1:18080/index.php';
$levels = array_map('intval', explode(',', $argv[2] ?? '1,8,32,64'));
$total = (int)($argv[3] ?? 200);
$caseCount = max(1, (int)($argv[4] ?? 32));
$seedBase = (int)($argv[5] ?? 20260930);

const MONEY_FIELDS = ['subtotal', 'memberDiscount', 'thresholdDiscount', 'couponDiscount', 'shipping', 'total'];

function money(float $v): string
{
    return number_format($v, 2, '.', '');
}

/** 返回 null 表示一致，否则返回差异说明。 */
function diff_quote(array $expected, array $got): ?string
{
    foreach (MONEY_FIELDS as $field) {
        if (!array_key_exists($field, $got)) {
            return "{$field} 缺失";
        }
        if (money((float)$expected[$field]) !== money((float)$got[$field])) {
            return "{$field} 期望 " . money((float)$expected[$field]) . " 实际 " . money((float)$got[$field]);
        }
    }

    $expectedCoupon = $expected['coupon'];
    $gotCoupon = $got['coupon'] ?? null;
    if (($expectedCoupon === null) !== ($gotCoupon === null)) {
        return 'coupon 存在性不一致';
    }
    if ($expectedCoupon !== null) {
        if (($expectedCoupon['applied'] ?? null) !== ($gotCoupon['applied'] ?? null)) {
            return 'coupon.applied 不一致';
        }
        if ($expectedCoupon['applied'] === false
            && ($expectedCoupon['reason'] ?? '') !== ($gotCoupon['reason'] ?? '')) {
            return 'coupon.reason 不一致';
        }
    }

    return null;
}

/** @return array<string,int|array> */
function run_round(string $baseUrl, int $concurrency, int $total, int $caseCount, int $seedBase): array
{
    $stats = [
        'requests' => 0,
        'ok' => 0,
        'http_error' => 0,
        'json_error' => 0,
        'id_mismatch' => 0,
        'seq_not_reset' => 0,
        'value_mismatch' => 0,
        'samples' => [],
        'pids' => [],
        'seq_values' => [],
    ];

    $expected = [];
    for ($case = 0; $case < $caseCount; $case++) {
        [$cart, $context] = reference_case($seedBase + $case);
        $expected[$case] = [
            'cart' => $cart,
            'context' => $context,
            'quote' => reference_quote($cart, $context),
        ];
    }

    $mh = curl_multi_init();
    $handles = [];
    $next = 0;

    $addNext = function () use (&$next, &$handles, $mh, $baseUrl, $total, $caseCount, $expected): bool {
        if ($next >= $total) {
            return false;
        }
        $i = $next++;
        $case = $i % $caseCount;
        $id = sprintf('c%d-n%d', $case, $i);

        $url = $baseUrl . '?' . http_build_query([
            'id' => $id,
            'cart' => json_encode($expected[$case]['cart']),
            'level' => $expected[$case]['context']['level'],
            'coupon' => $expected[$case]['context']['coupon'] ?? '',
            'ship' => $expected[$case]['context']['shipping'],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = [$id, $case];
        return true;
    };

    for ($i = 0; $i < $concurrency; $i++) {
        $addNext();
    }

    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            [$id, $case] = $handles[$key];
            unset($handles[$key]);

            $body = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $stats['requests']++;

            if ($httpCode !== 200) {
                $stats['http_error']++;
                if (count($stats['samples']) < 5) {
                    $stats['samples'][] = "{$id}: HTTP {$httpCode}";
                }
                $addNext();
                continue;
            }

            $payload = json_decode((string)$body, true);
            if (!is_array($payload) || !isset($payload['quote'])) {
                $stats['json_error']++;
                if (count($stats['samples']) < 5) {
                    $stats['samples'][] = "{$id}: 响应不是预期 JSON";
                }
                $addNext();
                continue;
            }

            $stats['pids'][(int)($payload['pid'] ?? 0)] = true;

            $failed = false;
            if (($payload['id'] ?? null) !== $id) {
                $stats['id_mismatch']++;
                $failed = true;
                if (count($stats['samples']) < 5) {
                    $stats['samples'][] = "{$id}: id 回显为 " . var_export($payload['id'] ?? null, true);
                }
            }

            $seq = (int)($payload['seq'] ?? 0);
            $stats['seq_values'][$seq] = ($stats['seq_values'][$seq] ?? 0) + 1;
            if ($seq !== 1) {
                $stats['seq_not_reset']++;
                $failed = true;
                if (count($stats['samples']) < 5) {
                    $stats['samples'][] = "{$id}: seq={$seq}（未按请求重置）";
                }
            }

            $diff = diff_quote($expected[$case]['quote'], $payload['quote']);
            if ($diff !== null) {
                $stats['value_mismatch']++;
                $failed = true;
                if (count($stats['samples']) < 5) {
                    $stats['samples'][] = "{$id}（用例 {$case}）: {$diff}";
                }
            }

            if (!$failed) {
                $stats['ok']++;
            }

            $addNext();
        }
    } while ($running > 0 || $handles !== []);

    curl_multi_close($mh);
    $stats['worker_count'] = count($stats['pids']);

    return $stats;
}

$failedTotal = 0;
$grandTotal = 0;
$grandOk = 0;
$allPids = [];
$allSeqs = [];

printf("目标 %s\n", $baseUrl);
printf("每轮 %d 请求，%d 个用例（每个用例被重复请求，分散到不同 worker）\n\n", $total, $caseCount);
printf("%-10s %-10s %-10s %-10s %-10s %s\n", '并发', '请求', '一致', '失败', 'worker', 'seq 取值');

foreach ($levels as $concurrency) {
    if ($concurrency < 1) {
        continue;
    }
    $stats = run_round($baseUrl, $concurrency, $total, $caseCount, $seedBase);
    $failed = $stats['requests'] - $stats['ok'];
    $failedTotal += $failed;
    $grandTotal += $stats['requests'];
    $grandOk += $stats['ok'];
    $allPids += $stats['pids'];

    foreach ($stats['seq_values'] as $seq => $count) {
        $allSeqs[$seq] = ($allSeqs[$seq] ?? 0) + $count;
    }

    $seqText = implode(',', array_map(
        static fn ($k, $v): string => "{$k}x{$v}",
        array_keys($stats['seq_values']),
        array_values($stats['seq_values'])
    ));

    printf(
        "%-10d %-10d %-10d %-10d %-10d %s\n",
        $concurrency,
        $stats['requests'],
        $stats['ok'],
        $failed,
        $stats['worker_count'],
        $seqText === '' ? '-' : $seqText
    );

    if ($stats['http_error'] || $stats['json_error'] || $stats['id_mismatch']
        || $stats['seq_not_reset'] || $stats['value_mismatch']) {
        printf(
            "  明细：http=%d json=%d id=%d seq=%d value=%d\n",
            $stats['http_error'],
            $stats['json_error'],
            $stats['id_mismatch'],
            $stats['seq_not_reset'],
            $stats['value_mismatch']
        );
    }
    foreach ($stats['samples'] as $sample) {
        printf("  样例：%s\n", $sample);
    }
}

printf("\n合计 %d 请求，一致 %d，失败 %d\n", $grandTotal, $grandOk, $failedTotal);
printf("参与处理的 worker 进程：%d 个；seq 取值分布：%s\n",
    count($allPids),
    implode(', ', array_map(static fn ($k, $v): string => "{$k}x{$v}", array_keys($allSeqs), array_values($allSeqs)))
);
exit($failedTotal === 0 ? 0 : 1);
