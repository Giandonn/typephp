<?php

/**
 * 并发探针（只用于 FPM 集成编译，见 ../project.fpm.yml）。
 *
 * 函数内的静态变量在标准 PHP 里属于"请求级"状态：php-fpm 的 worker 进程会长期存活，
 * 但每个请求都应从 0 重新开始计数。若这里出现累加，就说明状态跨请求泄漏了。
 * 请求脚本 fpm/public/index.php 把它放进响应里，由 fpm/bench/concurrency.php 校验。
 */
function promo_probe_seq(): int
{
    static $seq = 0;
    $seq++;
    return $seq;
}
