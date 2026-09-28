<?php

declare(strict_types=1);

return [
    [
        'name' => 'heartbeat_enable',
        'label' => '开启心跳',
        'type' => 'radio',
        'default' => '0',
        'placeholder' => '请选择是否开启心跳',
        'values' => [['label' => '开启', 'value' => '1'], ['label' => '关闭', 'value' => '0']],
        'options' => ['tip' => '开启后请务必绑定客户端，否则心跳将无法正常工作，客户端名称等于监控软件名称'],
        'rules' => ['required' => true, 'trigger' => ['change'], 'message' => '请选择是否开启心跳'],
    ],
    [
        'name' => 'qrcode_type',
        'label' => '二维码类型',
        'type' => 'select',
        'default' => 'personal',
        'placeholder' => '请选择二维码类型',
        'values' => [
            ['label' => '个人码', 'value' => 'personal'],
            ['label' => '赞赏码', 'value' => 'zanshang'],
            ['label' => '店员码', 'value' => 'clerk'],
            ['label' => '商业码', 'value' => 'business'],
            ['label' => '收款单', 'value' => 'receipt'],
            ['label' => '经营码', 'value' => 'operate'],
            ['label' => '企业微信', 'value' => 'wework'],
        ],
        'rules' => ['required' => true, 'trigger' => ['change'], 'message' => '请选择二维码类型'],
    ],
    [
        'name' => 'type',
        'label' => '收款码类型',
        'type' => 'select',
        'default' => 'url',
        'placeholder' => '请选择收款码类型',
        'when' => "this.formModel.options.qrcode_type != 'zanshang'",
        'values' => [['label' => '地址', 'value' => 'url'], ['label' => '图片', 'value' => 'image']],
        'rules' => ['required' => true, 'trigger' => ['change'], 'message' => '请选择收款码类型'],
    ],
    [
        'name' => 'qrcode',
        'label' => '收款码地址',
        'type' => 'input',
        'default' => '',
        'placeholder' => '请输入收款码地址',
        'when' => "this.formModel.options.type == 'url' && this.formModel.options.qrcode_type != 'zanshang'",
        'options' => ['append_deqrocde' => 1],
        'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请输入收款码地址'],
    ],
    [
        'name' => 'qrcode_file',
        'label' => '收款码图片',
        'type' => 'image',
        'default' => '',
        'placeholder' => '请上传收款码图片',
        'when' => "this.formModel.options.type == 'image' || this.formModel.options.qrcode_type == 'zanshang'",
        'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请上传收款码图片'],
    ],
];
