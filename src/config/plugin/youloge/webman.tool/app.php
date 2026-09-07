<?php
$config = [
    'enable' => true,
    'qiniu'=>[
        'ak'=>'',
        'sk'=>'',
    ],
    'meilisearch' => [
        'ak'=>'',
        'sk'=>'',
        'host'=>'',
        'port'=>'',
        'protocol'=>'',
    ],
    // 请求代理配置
    'proxy'=>[
        ['addr'=>'1.1.1.1','prot'=>'8888','pass'=>'user:pass']
    ],
    // 支付配置与小程序配置均为appid=>config的形式
    '0000'=>[

    ]
];
// 多行配置参数
$config['0000']['cert'] = <<<EOT
多行配置参数
多行配置参数
EOT;

return $config;
