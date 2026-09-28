<?php

declare(strict_types=1);

namespace XArrPay\Payment;

final class YunWechatYybXdPlugin extends WechatCloudXdPlugin
{
    public function name(): string
    {
        return 'yun_wechat_yyb_xd';
    }
}
