<?php

declare(strict_types=1);

return [
    [
        'name' => 'code_type',
        'label' => '收款码类型',
        'type' => 'select',
        'default' => 'qrcode',
        'placeholder' => '请选择收款码类型',
        'values' => [
            ['label' => '二维码', 'value' => 'qrcode'],
            ['label' => '文本', 'value' => 'text'],
        ],
        'rules' => ['required' => true, 'trigger' => ['change'], 'message' => '请选择收款码类型'],
    ],
    [
        'name' => 'qrcode',
        'label' => '收款码地址或文本',
        'type' => 'input',
        'default' => '',
        'placeholder' => '请输入收款码地址或文本内容',
        'when' => "this.formModel.options.code_type == 'text' || this.formModel.options.qrcode_data == ''",
        'options' => ['tip' => '二维码地址可以是微信收款码地址；文本类型用于客户端复制展示'],
        'rules' => ['required' => true, 'trigger' => ['input', 'blur'], 'message' => '请输入收款码地址或文本内容'],
    ],
    [
        'name' => 'qrcode_data',
        'label' => '收款码图片',
        'type' => 'image',
        'default' => '',
        'placeholder' => '请上传收款码图片',
        'when' => "this.formModel.options.code_type == 'qrcode'",
        'options' => ['tip' => '上传图片后可直接展示二维码；同时填写地址时优先使用图片'],
    ],
];
