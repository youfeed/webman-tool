<?php
$config = [
    'enable' => true,
    // 七牛配置数组形式
    'qiniu' => [
        'ak' => '',
        'sk' => '',
    ],
    'meilisearch' => [
        'ak' => '',
        'sk' => '',
        'host' => '',
    ],
    'youloge'=>[
        'apikey'=>'',
        'secret'=>'',
    ],
    // 代理配置可以取(proxy01,proxy02...)
    'proxy' => ['addr' => '1.1.1.1', 'prot' => '8888', 'pass' => 'user:pass'],
    // 支付配置与小程序配置均为appid=>config的形式
    '0000' => [
        'apiclient_key' => '',
        'serial_no' => ''
    ],
    '{微信支付serial}' => [
        'platform_cert' => '对应的平台证书(应使用多行配置参数)'
    ]
];
// 多行配置参数
$config['0000']['cert'] = <<<EOT
多行配置参数
多行配置参数
EOT;

return $config;
