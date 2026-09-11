# Youloge.tool Webman 辅助函数工具箱

![Brightgreen](https://img.shields.io/badge/@-micateam-brightgreen.svg) ![Packagist](https://img.shields.io/packagist/v/youloge/webman.tool) ![Languages](https://img.shields.io/github/languages/top/youfeed/webman.tool.svg) ![Packagist Downloads](https://img.shields.io/packagist/dt/youloge/webman.tool) ![License ](https://img.shields.io/packagist/l/youloge/webman.tool)

> 使用前看一下下面辅助函数：尤其注意`函数名称问题`

- 代码风格极简 欢迎提交代码
- 几行代码就能接入微信支付/支付宝
- 一行代码生成一个虚拟文件并上传

### 安装使用

> `composer require youloge/webman.tool`

- 如果要使用 `onRequest` 请求封装 请安装`composer require workerman/http-client`
- 如果要使用 `onQueue` 队列封装 请安装`composer require workerman/redis-queue`
- 已经内置函数 [ini()](https://www.workerman.net/plugin/153) 与 [useValidate()](https://www.workerman.net/plugin/153) 安装本插件那二个插件可以不用安装

### 配置文件
配置位置：`config\plugin\youloge\webman.tool\app.php`
```php
<?php
$config = [
    'enable' => true,
    'meilisearch' => [],
    'qiniu01'=>['ak'=>'','sk'=>'']
    ...
];
$config['1232456']['cert'] =  <<<EOT
-----BEGIN CERTIFICATE-----
...
-----END CERTIFICATE-----
EOT;

return $config;
```
### 项目地址

[Github Youloge.Tool](https://github.com/youfeed/webman.tool) Star 我 `有帮助的话，记得给个star` 能提交点代码最好

- 2.0.1 [2026-09-11] 全新`V2`版本 一般已`use开头`
- 1.0.0 [2025-03-15]-[2026-03-11] V1版本不在更新先升级为全新`V2`版本


## 示例代码 - 辅助辅助 函数还是要配合代码食用才香~

### 示例：`表单输入验证器` 查看使用详情文档 [webman.validate](https://www.workerman.net/plugin/edit/188)


### `Redis排它锁` - `useLock`

- @param string $key 实际key为`Lock:$key`
- @param int|string $param 默认10 传秒数加锁 字符串解锁(只能解锁自己的锁)
- @return bool|string 加锁成功返回token,失败false 解锁是返回bool

```php
$token = useLock('y',20); 获取一个20秒的锁
useLock('y',$token); 解锁
```

### `Redis缓存读写与自增器` - `useCache`

- @param string $key 实际key为`Cache:$key`
- @param string|array $params 默认`read` 模式参数 `read`=读取 `once`=读并删除 `incr`=计数器自增 `ttl`=查看剩余有效期
- @param int $expire=300  写入时为缓存有效期; incr模式下代表自增步长(默认步长1)

```php
useCache('y',['x'=>100,'y'=>200],600); // 缓存一个对象 有效期600秒
useCache('y'); // 读取缓存对象
useCache('y','ttl'); // 查看剩余有效期
useCache('y','once'); // 读取缓存对象并删除对象
useCache('x',100); // 缓存一个对象 有效期300秒
useCache('x','incr',5); // 给缓存对象自增5 105
```

### `Redis 限速器` - `useLimits`
- @param string $key 限速键名 实际key为`limits:$key`
- @param int $limit 限速次数
- @param int $ttl 过期时间
- @param int $locking 锁定时间 >0 则为滑动窗口锁定模式
- @return bool true=放行 false=超限

```php
useLimits('y'); // 60秒内最多1次
useLimits('y',10,300); // 300秒内最多10次
useLimits('y',10,300,20); // 300秒内最多10次 每次锁定20秒 (每20秒放行1个 总放行不超10次)
```

### `Redis 自增器` - `useIncrBy`
- 实际key为`Youloge:UUID`的`Hash表`里`$name`
- @param string $name 自增器名称 
- @param int $step 自增步长

```php
useIncrBy('y'); // 默认步长1
useIncrBy('y',10); // 增长10
useIncrBy('y',-5); // 减少5
```

### `美丽说搜索` - `apiMeilisearch`查询 + `vipMeilisearch`管理
- @param string $route 路由
- @param array $params 参数
- @param string $method 方法
- @return array|string 返回数据
```php
vipMeilisearch('version'); // 获取版本信息
apiMeilisearch("indexes/video/search", ['q'=>'*'], 'POST'); // 查询视频
```

### 队列封装 - `useQueue`
- @param string $name 队列名称
- @param array $data 数据
- @param int|null $delay 可选：延迟时间
- @return int 1 成功 0 失败
```php
useQueue('y',['x'=>100,'y'=>200]); // 添加数据到 y 队列
useQueue('y',['x'=>100,'y'=>200],10); // 添加数据到 y 队列 10秒后消费
```

### `2FA认证器` - `useAuthenticator`
> 标准 TOTP 令牌 RFC6238
- @param string $secret Base32编码的密钥
- @param int|null $time 可选：时间戳。默认为null，表示当前时间。
- @return array 返回三组验证码

```php
useAuthenticator('account:issuer'); // 返回一组 TOTP参数对(含密钥)
useAuthenticator('GQBWBS7AAEBECCUJ'); // 返回当前时间戳的验证码(3组)
useAuthenticator('GQBWBS7AAEBECCUJ',1741877199); // 返回指定时间戳的验证码
useAuthenticator('GQBWBS7AAEBECCUJ','123456'); // 验证'123456' 是否在当前时间戳的验证码范围
```

### `异步网络请求封装` - `useRequest`
- 使用的是：[http-client](https://www.workerman.net/doc/workerman/components/workerman-http-client.html)
- @param string $url 请求网址
- @param array $options 请求配置
- 请求返回 返回 [JOSN] 非对象返回 [raw=响应内容]
- 错误返回 ['err'=>500,'msg'=>'错误信息']
```php
useRequest('https://example.com/',['method' => 'POST','headers'=>[]])
```

### `HTTP代理网络请求` - `httpProxy`
> 使用的`curl` 以后改为`workerman-http-client`
- @param string $url 请求网址
- @param array $options 请求配置
- 请求返回 返回 [JOSN] 非对象返回 [raw=响应内容]
- 错误返回 ['err'=>500,'msg'=>'错误信息']
```php
httpProxy('https://example.com/',['method' => 'POST','headers'=>[]]); // 与 useRequest 一样使用
```

### `虚拟文件上传` - `useVirtualFile`
- @param string $url 上传地址
- @param array $files 文件类型数据 ['表单名称'=>['name'=>'文件名称','mime'=>'文件类型','data'=>'数据内容']]
- @param array $body 其他表单数据
- @param array $header 其他表单请求头
- @return mixed 上传结果
- 错误返回 ['err'=>500,'msg'=>'错误信息']
```php
useVirtualFile('https://upload.com/',[['file'=>['name'=>'test.txt','mime'=>'text/plain','data'=>'test data']]]);
```

> 算法相关

### `Base58编码解码` - `useBase58`
- @param int|string $input int：编码数字 string：解码字符串
- @param int $digits 随机混淆盐位数，默认3
- @return int|string 编码数字|解码字符串
```php
$uuid = useIncrBy('uuid'); // 自增生成唯一ID
$encode = useBase58($uuid); // 数字输入 输出短编码
$decode = useBase58($encode); // 短编码输入 输出数字

$decode === $uuid // true
```

### `安全Base64编码` - `useBase64_encode`
- @param string $string 待编码的字符串
@return string 编码后的字符串
```php
useBase64_encode('123') //
```

### `安全Base64解码` - `useBase64_decode`
- @param string $data 待解码的数据
- @param bool $strict 默认false 是否丢弃非Base64字符
- @return string|false
```php
useBase64_decode('ABC')
```

### `AES128加解密` - `useAES128`
> (AES-128-CBC)封装方便使用: 注意输入类型
- @param string|array $input array=加密(数组转json加密)，string=解密(传入加密串)
- @param string $salt 密钥盐 默认空
- @param string $info 标记用途 默认空
- @return array|string|false 加密返回safe-base64字符串；解密返回原数组；失败false
```php
useAES128('ABC','123') // 解密模式
useAES128(['x'=>100],'123') // 加密模式
```

### `AES256加解密` - `useAES256`
> (AES-256-CBC)封装方便使用: 注意输入类型
- @param string|array $input array=加密(数组转json加密)，string=解密(传入加密串)
- @param string $salt 密钥盐 默认空
- @param string $info 标记用途 默认空
- @return array|string|false 加密返回safe-base64字符串；解密返回原数组；失败false
```php
useAES256('ABC','123') // 解密模式
useAES256(['x'=>100],'123') // 加密模式
```

### `Youloge加解密` - `useYouloge`
> 平台专用洋葱加解密 (不包含签名生成算法Sign仅由平台内部使用)
- @param string|array $input 待加解密的数据
- @param string|null $info 签名密钥标记 默认`onion:hmac-v1` 如果配置为`null` 表示跳过HMAC签名校验(用于解密官方数据)
- @param string $appid 配置参数主键 默认`youloge`
- @return string|array|false 加密返回safe-base64字符串；解密返回原字符串；失败false

```php
useYouloge(['x'=>100]); // 加密模式 输入数组
useYouloge('ABC'); // 解密模式 输入字符串
useYouloge('ABC',null); // 解密官方数据 例如ACCESS_TOKEN PAYLOAD
```

### `腾讯云请求体` - `useTencentRequest`
- 构造腾讯云请求体: TC3-HMAC-SHA256
- @param string $method  请求方式 GET/POST
- @param string $endpoint_action_version_region  接入点/方法/版本/区域 
- @param array $payload  请求载体 无参数时 设为[],null,false,0 即可
- @param string $appid  选择那个商户id下得的证书
```php
$payload = [ 'PhoneNumberSet'=>['+8617605509012'] ];
$options = tencent_request('POST','sms.tencentcloudapi.com/DescribePhoneNumberInfo/2021-01-11/ap-nanjing',$payload,'1253985496');
$request = useRequest(...$options); // 异步请求
$request = httpProxy(...$options); // 代理请求
```
 
> 七牛相关 [配置文件读取 qiniu.ak qiniu.sk]

### `七牛管理请求体` - `useQiniu`
- @param string $method 请求方式 GET/POST/PUT/DELETE
- @param string $uri 请求网址路径
- @param array $query 查询参数
- @param array|string $body 请求内容
- @param string $appid 配置参数主键 默认`qiniu`
```php
$$options = useQiniu('GET','uc.qiniuapi.com/bucketTagging?bucket=<BucketName>'); // 查询<BucketName>的标签
$request = useRequest(...$options); // 异步请求
```

### `七牛管理请求体(老版)` - `useQiniuQbox`
- @param string $method 请求方式 GET/POST/PUT/DELETE
- @param string $uri 请求网址路径
- @param array $query 查询参数
- @param array|string $body 请求内容
- @param string $appid 配置参数主键 默认`qiniu`
```php
useQiniuQbox('GET','uc.qiniuapi.com/bucketTagging?bucket=<BucketName>'); // 查询<BucketName>的标签
```

### `七牛云上传凭证` - `useQiniuToken`
- @param array $params 待签名数组对象
- @param string $appid 配置参数主键 默认`qiniu`
- @return string 返回上传Token

```php
useQiniuToken([
    'scope' => '<BucketName>:<Key>',
    'deadline' => time() + 3600
]);
```

### `七牛云下载链接` - `useQiniuDownload`
- @param string $url 待签名下载网址
- @param int $second 可选：设置有效时间 默认3600秒
- @param string $attname 可选：设置下载文件名 默认没有
- @return string 返回签名后的下载网址
```php
useQiniuDownload('https://example.com/1.jpg');
useQiniuDownload('https://example.com/1.jpg',500);
useQiniuDownload('https://example.com/1.jpg',500,'x.jpg');
```


> 算法类

### `私钥签名` - `usePrivateKeySign`
- @param string $string 待签名字符串
- @param string $appid 选择那个id下得的证书
- 返回数组 成功 [err=>200,data=>base64] 失败 [err=>500,msg=>'签名错误']
```php
usePrivateKeySign('123','1253985496');
```

### `公钥验签` - `usePublicKeyVerify`
- @param string $string 待签名字符串
- @param string $Signature 待验签签名
- @param string $appid 选择那个id下得的证书
- 返回数组 成功 [err=>200,data=>base64] 失败 [err=>500,msg=>'签名错误']
```php
usePublicKeyVerify('123','123','1253985496');
```

### `微信支付请求体构造` - `useWeixinPayRequest`
- @param string $method 请求网络方式 GET/POST
- @param string $router 请求网络路径 必须'/'开头
- @param array $data JSON数据 不传设置为 '' false 0 即可
- @param string $appid 选择那个商户id下得的证书
- @return array 返回请求体数组
```php
useWeixinPayRequest('GET','/v3/certificates',[],'1253985496')
```

### `微信支付回调验证` - `useWeixinPayVerify`
- 微信支付回调验证 
- @param object $request Request 给返回对象传进来
- @param string $appid 选择那个商户id下得的证书
- @return array 成功返回 对象返回JSON 否则返回 []
- @return array 失败返回 ['err'=>500,'msg'=>Exception]

```php
useWeixinPayVerify($request,'1253985496')
```

### `微信支付解密V3` - `useWeixinPayDecryptV3`
- @param array $encrypt 解密数据 要有['ciphertext','nonce','associated_data'] 
- @param string $mchid 选择那个商户id下得的证书
- 成功返回 对象返回JSON 否则返回 ['raw'=>$raw]
- 失败返回 ['err'=>500,'msg'=>Exception]

```php
useWeixinPayDecryptV3(); // 
```

### `支付宝支付请求体构造` - `useAliPayRequest`
- @param string $method  接口名称 alipay.trade.create ...
- @param array $data  请求参数(公共参数可以在这里覆盖)
- @param string $appid  选择那个商户id下得的证书
```php
useAliPayRequest(`alipay.trade.create`,[
    'biz_content'=>[
        // 支付参数
    ],
    'notify_url'=>'https://a.com'
],12345678); // 返回请求 options
```

### `支付宝支付回调验证` - `useAliPayVerify`
> 支付验证公钥是固定的：请在配置文件中配置
- @param object $request Request 给返回对象传进来
- @param string $appid  选择那个商户id下得的证书
- 成功返回 对象返回JSON 否则返回 []
- 失败返回 ['err'=>500,'msg'=>Exception]
```php
useAliPayVerify($request,'alipay');
```



> 但行好事 莫问前程

![wallet.micateam](https://img.youloge.com/wallet/micateam!0)