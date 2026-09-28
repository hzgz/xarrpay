<?php

declare(strict_types=1);

return [
    ['name' => 'gateway', 'label' => '选择网关', 'type' => 'select', 'default' => '', 'placeholder' => '请选择网关', 'options' => ['chose_gateway' => 1], 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请选择网关']],
    ['name' => 'login_protocol', 'label' => '登录协议', 'type' => 'radio', 'default' => 'yyb', 'values' => [['label' => '应用宝', 'value' => 'yyb'], ['label' => '手游助手', 'value' => 'app'], ['label' => '电脑管家', 'value' => 'pc_tool']]],
    ['name' => 'type', 'label' => '收款码类型', 'type' => 'select', 'default' => 'qrcode', 'options' => ['tip' => '支持普通二维码、图片收款码和个人经营收款码收款；流水备注由插件内置规则匹配'], 'values' => [['label' => '二维码', 'value' => 'qrcode'], ['label' => '图片', 'value' => 'image']]],
    ['name' => 'qrcode', 'label' => '收款码地址', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.type == 'qrcode'", 'options' => ['append_deqrocde' => 1], 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请输入收款码地址']],
    ['name' => 'qrcode_file', 'label' => '收款码图片', 'type' => 'image', 'default' => '', 'when' => "this.formModel.options.type == 'image'", 'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请上传收款码图片']],
    ['name' => 'poll_window_seconds', 'label' => '流水匹配窗口秒', 'type' => 'input', 'default' => '600', 'placeholder' => '默认 600 秒', 'options' => ['tip' => '仅匹配最近时间窗口内的收款流水，最小值为 60 秒']],
    ['name' => 'proxy_mode', 'label' => '代理模式', 'type' => 'select', 'default' => 'no_proxy', 'options' => ['tip' => '仅用于靓仔云端登录及获取小程序 code'], 'values' => [['label' => '不使用代理', 'value' => 'no_proxy'], ['label' => '使用自定义代理', 'value' => 'custom_proxy'], ['label' => '使用本站代理池', 'value' => 'cloud_proxy']]],
    ['name' => 'proxy', 'label' => 'Socks5 代理地址', 'type' => 'input', 'default' => '', 'when' => "this.formModel.options.proxy_mode == 'custom_proxy'", 'placeholder' => 'socks5://127.0.0.1:1080'],
    ['name' => 'proxy_pool_id', 'label' => '绑定代理池', 'type' => 'chose_proxy_pool', 'default' => '-1', 'when' => "this.formModel.options.proxy_mode == 'cloud_proxy'"],
    ['name' => 'proxy_pool_item_id', 'label' => '绑定代理', 'type' => 'hidden', 'default' => '0'],
    ['name' => 'bind_token', 'label' => '绑定信息', 'type' => 'hidden', 'default' => ''],
    ['name' => 'uid', 'label' => '网关账号', 'type' => 'hidden', 'default' => ''],
    ['name' => 'custom_session_key', 'label' => '小程序会话', 'type' => 'hidden', 'default' => ''],
    ['name' => 'tally_openid', 'label' => '账本用户', 'type' => 'hidden', 'default' => ''],
    ['name' => 'bound_login_protocol', 'label' => '当前登录协议', 'type' => 'hidden', 'default' => ''],
];
