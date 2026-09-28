<?php

declare(strict_types=1);

return [
    ['name' => 'gateway', 'label' => '选择网关', 'type' => 'select', 'default' => '', 'placeholder' => '请选择网关', 'options' => ['chose_gateway' => 1], 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请选择网关']],
    ['name' => 'pay_mode', 'label' => '收款方式', 'type' => 'radio', 'default' => 'receipt', 'options' => ['tip' => '微信收款单支持免输入，微信小账本免挂模式'], 'values' => [['label' => '收款单', 'value' => 'receipt'], ['label' => '小账本', 'value' => 'smallbook']], 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请选择收款方式']],
    ['name' => 'sid', 'label' => 'SID', 'type' => 'input', 'default' => '', 'options' => ['tip' => '扫码登录后自动获取对应 SID，也可手动填写'], 'placeholder' => '请输入 SID 或扫码登录后自动获取'],
    ['name' => 'account_list', 'label' => '账号列表（一行一组）', 'type' => 'textarea', 'default' => '', 'when' => "this.formModel.options.sid && this.formModel.options.pay_mode !== 'smallbook'", 'hidden_list' => 1, 'placeholder' => '清空后会重新获取', 'options' => ['tip' => '清空后保存会重新获取；如需切换账号 ID，可复制对应账号信息替换下面字段']],
    ['name' => 'aid', 'label' => '账号 ID', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.sid && this.formModel.options.pay_mode !== 'smallbook'", 'placeholder' => '请输入账号 ID'],
    ['name' => 'account_type', 'label' => '账号类型', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.sid && this.formModel.options.pay_mode !== 'smallbook'", 'placeholder' => '请输入账号类型（1-3）'],
    ['name' => 'shop_id', 'label' => '店铺 ID', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.sid && this.formModel.options.pay_mode !== 'smallbook'", 'options' => ['tip' => '仅在实际有店铺 ID 时填写'], 'placeholder' => '请输入店铺 ID'],
    ['name' => 'proxy_mode', 'label' => '代理模式', 'type' => 'select', 'default' => 'no_proxy', 'values' => [['label' => '不使用代理', 'value' => 'no_proxy'], ['label' => '使用自定义代理', 'value' => 'custom_proxy'], ['label' => '使用本站代理池', 'value' => 'cloud_proxy']], 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请选择代理模式']],
    ['name' => 'proxy', 'label' => 'Socks5 代理地址', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.proxy_mode == 'custom_proxy'", 'options' => ['tip' => '格式：socks5://IP:端口，或 socks5://用户名:密码@IP:端口'], 'placeholder' => '例如：socks5://127.0.0.1:1080'],
    ['name' => 'proxy_pool_id', 'label' => '绑定代理池', 'type' => 'chose_proxy_pool', 'default' => '-1', 'when' => "this.formModel.options.proxy_mode == 'cloud_proxy'", 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请选择代理池']],
    ['name' => 'proxy_pool_item_id', 'label' => '绑定代理', 'type' => 'hidden', 'default' => '0'],
    ['name' => 'bind_token', 'label' => '绑定的用户信息', 'type' => 'hidden', 'default' => ''],
    ['name' => 'guanjia_ref', 'label' => '电脑管家账号', 'type' => 'hidden', 'default' => ''],
    ['name' => 'flow_endpoint', 'label' => '流水接口地址', 'type' => 'input', 'default' => '', 'placeholder' => '请输入已部署网关提供的真实流水接口地址', 'options' => ['tip' => '未配置真实接口时定时任务会明确失败，不会伪造流水'] ],
    ['name' => 'flow_http_method', 'label' => '流水接口方法', 'type' => 'select', 'default' => 'GET', 'values' => [['label' => 'GET', 'value' => 'GET'], ['label' => 'POST', 'value' => 'POST']]],
];
