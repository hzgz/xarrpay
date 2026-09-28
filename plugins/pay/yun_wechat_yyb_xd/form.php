<?php

declare(strict_types=1);

$items = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'yun_wechat_gj_xd' . DIRECTORY_SEPARATOR . 'form.php';
foreach ($items as &$item) {
    if (($item['name'] ?? '') === 'guanjia_ref') {
        $item['name'] = 'yyb_ref';
        $item['label'] = '应用宝账号';
    }
}
unset($item);
return $items;
