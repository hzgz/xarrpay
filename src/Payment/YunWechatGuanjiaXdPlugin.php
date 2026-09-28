<?php

declare(strict_types=1);

namespace XArrPay\Payment;

final class YunWechatGuanjiaXdPlugin extends WechatCloudXdPlugin
{
    public function name(): string
    {
        return 'yun_wechat_gj_xd';
    }
}
