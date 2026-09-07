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
return [
    'enable' => true,
    'meilisearch' => [],

];

```
### 项目地址

[Github Youloge.Tool](https://github.com/youfeed/webman.tool) Star 我 `有帮助的话，记得给个star` 能提交点代码最好

- 1.5.0 [2026-03-11] `useValidate`升级为V2版本，并修复个别函数问题
- 1.4.1 [2025-11-13] `useValidate`新增`sprintf`,`format`规则，格式化字符串
- 1.4.0 [2025-10-25] `useValidate`优化`required`规则，允许`0、'0'、false、空数组`
- 1.3.3 [2025-08-14] `useValidate`新增`array`和`object`数据类型
- 1.3.0 [2025-08-12] `useValidate` 默认数据类型与默认值：数据类型修复为一致
- 1.2.8 [2025-03-20] 增加`useValidate`基本数据类型`int:100`,`float:1.02`,`bool:false`,`string:默认值` 提供默认值支持
- 1.2.7 [2025-03-16] 优化表单过滤器`useValidate`并拆分独立版本[Webman.validate](https://www.workerman.net/plugin/188)
- 1.2.4 [`2025-03-15`] 新增输入过滤器`useValidate`优雅处理表单输入
- 1.2.2 [2025-03-13] 新增谷歌令牌辅助函数 `secret_base32` => `useTOTP`
- 1.0.1 增加 构造腾讯云请求体
- 0.0.9 迁移多个辅助函数


## 示例代码 - 辅助辅助 函数还是要配合代码食用才香~

### 示例：`表单输入验证器` 查看使用详情文档 [webman.validate](https://www.workerman.net/plugin/edit/188)


```php
@[
     'err' => $err, 'msg' => $msg, 
     'uuid' => $uuid, 'payer' => $payer, 'encrypt' => $encrypt
] = useValidate($request->all(), [
     'uuid' => 'int:10000',
     'payer' => 'required|string',
     'package' => 'required|string',
]);
$err && throw new Exception($msg, $err);
```
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
useCache('y',['x'=>100,'y'=>200],300); // 缓存一个对象 有效期300秒
useCache('y'); // 读取缓存对象
useCache('y','ttl'); // 查看剩余有效期
useCache('y','once'); // 读取缓存对象并删除对象
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
### `队列封装` - `useQueue`
- @param string $queue 队列名称
- @param array $data 数据
- @param int|null $delay 可选：延迟时间
- @return int 1 成功 0 失败

```php
useQueue('y',['x'=>100,'y'=>200]); // 添加数据到 y 队列
useQueue('y',['x'=>100,'y'=>200],10); // 添加数据到 y 队列 10秒后消费
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


### `安全Base64编码` - `useBase64_encode`
- @param string $string 待编码的字符串
@return string 编码后的字符串
```php
useBase64_encode('123')
```


### `安全Base64解码` - `useBase64_decode`
- @param string $data 待解码的数据
- @param bool $strict 默认false 是否丢弃非Base64字符
- @return string|false
```php
useBase64_decode('ABC')
```
> 腾讯相关


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

> 七牛相关

### `七牛云私有下载链接` - `useQiniu_download`
- @param string $url 待签名下载网址
- @param int $second 可选：设置有效时间 默认3600秒
- @param string $attname 可选：设置下载文件名 默认没有

```php
useQiniu_download('https://example.com/1.jpg');
useQiniu_download('https://example.com/1.jpg',500);
useQiniu_download('https://example.com/1.jpg',500,'x.jpg');
```

### `七牛签名` - `useQiniu_sign`
- 用来生成Token
- @param array $params 待签名数组对象
- @return string 签名后的字符串
```php
useQiniu_sign(['bucket'=>'y','key'=>'x.jpg']); // 
```

### `七牛签名` - `useQiniu_auth`
- 用来生成Token
- @param array $params 待签名数组对象
- @param string $ContentType 可选：设置请求头 Content-Type 默认application/json
- @return array 返回请求头 ['Authorization: QBox token','Content-Type: application/json']
```php
useQiniu_auth(['bucket'=>'y','key'=>'x.jpg']); // 
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

### `微信支付请求体构造` - `useWeixinRequest`
- @param string $method 请求网络方式 GET/POST
- @param string $router 请求网络路径 必须'/'开头
- @param array $data JSON数据 不传设置为 '' false 0 即可
- @param string $appid 选择那个商户id下得的证书
- @return array 返回请求体数组
```php
useWeixinRequest('GET','/v3/certificates',[],'1253985496')
```

### `微信支付回调验证` - `useWeixinVerify`
- 微信支付回调验证 
- @param object $request Request 给返回对象传进来
- @param string $appid 选择那个商户id下得的证书
- @return array 成功返回 对象返回JSON 否则返回 []
- @return array 失败返回 ['err'=>500,'msg'=>Exception]

```php
useWeixinVerify($request,'1253985496')
```

### 示例：`腾讯云短信SMS号码查询`

> 简单粗暴 不需要安装各种`腾讯云各种SDK`配好密钥 直接开干

```php
    // 第二个参数为一个组合 `接入点/方法/版本/区域(可选参数)`
    $options = tencent_request('POST','sms.tencentcloudapi.com/DescribePhoneNumberInfo/2021-01-11/ap-nanjing',[
            'PhoneNumberSet'=>['+8617605509012']
        ],'1253985496');
    $request = onRequest(...$options); // 异步请求
    $request = httpProxy(...$options); // 代理请求
```

### 示例：`请求微信证书`

> 就是这么简单 发起 JSAPI H5 支付都是同理，比如 JSAPI 支付`统一下单之后`在调用一下签名组装一下`payment`参数即可支付

```php
$options = weixin_request('GET','/v3/certificates',[],'商户ID');
@['data'=>$data] = $request = onRequest(...$options);
// 返回一个V3加密后的数组
$list = [];
foreach($data as ['serial_no'=>$serial_no,'encrypt_certificate'=>$encrypt_certificate]){
	// 使用微信V3解密 weixin_decrypt
	$certificate = weixin_decrypt($encrypt_certificate,'商户ID');
	// 按照证书序列号`PEM格式`保存到本地
	file_put_contents("$serial_no.pem",$certificate);
	$list[$serial_no] = $certificate;
}
//
return $list;
```
-
### 示例：`上传JSON文件到七牛`

> 上传`一个JSON片段文件`并指定保存文件名到`config/100.json`, 二进制数据没测试

```php
     $url = qiniu_sign([
        'scope'=>"buket:100.json",
        'deadline'=>time()+300,
        'forceSaveKey'=>true,
        'saveKey'=>"config/100.json",
        'returnBody'=>'{"err": 200,"hash": $(etag)}',
        'insertOnly'=>0,
    ]);
    $data = json_encode([
        'uuid'=>100,
        'target'=>'https://www.abc.com',
        'type'=>'1'
    ]);
    $file = ['name'=>"100.json",'mime'=>'application/json','data'=>$data];
    @['err'=>$err,'hash'=>$hash] = $virtual = virtualFile("https://upload.qiniup.com/?token=$url",['file'=>$file],['key'=>'100']);
```

### 示例：`代理请求网网址`

> 网站支持`github登录`服务器在国内，运营商会屏蔽你的访问，这时候可以使用代理请求，数据结构与`onRequest` 一样

```php
    @['appid'=>$appid,'code'=>$code] = $request->all();
    @['secret'=>$secret] = config("youloge.$appid"); // 配置参数格式统一起来
    // 换取`access_token`
    @['access_token'=>$access_token] = $data = `httpProxy`(
    "https://github.com/login/oauth/access_token?client_id=$appid&client_secret=$secret&code=$code",[
      'headers'=>[
        'Accept'=>'application/json'
        ]
    ]);
    if($access_token == null){ return ['err'=>100800,'msg'=>'Github授权失败']; }
    // 获取用户信息
    @['email'=>$mail] = $data = httpProxy('https://api.github.com/user',[
      'headers'=>['Authorization'=>"Bearer $access_token",'Accept'=>'application/json','User-Agent'=>'Youloge-API']
    ]);
    if($mail == null){ return ['err'=>100801,'msg'=>'Github账户未认证']; }
    // 用登录信息 查询数据库 ...

```

---

## 代码工具箱

---

### 安全的 base64 编码

```php
safe_base64_encode($data);
safe_base64_decode($data);
```

### 生成不重复字符 - 用于验证码

- 使用 Base32 字符集 ABCDEFGHIJKLMNOPQRSTUVWXYZ234567
- @param int $len=4 长度

```php
rand_base32($len=4)
```

### 生成重复的字符 - 用于密钥密码

- 使用 Base32 字符集
- @param int $len=16 长度
- @param string $prefix='' 前缀

```php
secret_base32($len=16,$prefix='')
```

### Mysql 实例

- 推荐使用 模型
- [laravel 数据库](https://github.com/illuminate/database)
- @param string $table 表名

```php
onMysql($table)
```

### Redis 实例 - 配置文件读取默认

- 返回句柄

```php
onRedis()
```

### Redis 数组执行(自动 close)

- runRedis('HGET',["wallet",$uuid])
- runRedis('HINCRBY',["wallet",$uuid,10])

```php
runRedis($method,$params)
```

### [webman-queue] 队列封装

- @param string $queue 队列名称
- @param array $data 数据
- @param int $delay 可选：延迟时间

```php
     onQueue($queue,$data,$delay=0)
```

### [http-client] 异步网络请求封装

- @param string $url 请求网址
- @param array $options 请求配置
- 示例：'https://example.com/', ['method' => 'POST','version' => '1.1','headers' => ['Connection' => 'keep-alive'],'data' => ['key1' => 'value1', 'key2' => 'value2'],]
- 请求返回 返回 [JOSN] 非对象返回 [raw=响应内容]
- 错误返回 ['err'=>500,'msg'=>'错误信息']

```php
     onRequest($url,$options=[])
```

---

网络代理 微信支付/支付宝 都需`config/youloge.php` 配置文件

---

### HTTP 代理网络请求 - 配置文件随机读取 [youloge.proxy[0~n]]

- 请求参数与 httpProxy == onRequest == http-client(request) 一样
- @param string $url 请求网址
- @param array $options 请求配置

```php
     httpProxy($url,$options=[])
```

### 生成虚拟文件对象并上传 - 支持多文件

- @param string $url 上传地址
- @param array $files 文件类型数据 ['表单名称'=>['name'=>'文件名称','mime'=>'文件类型','data'=>'数据内容']]
- @param array $body 其他表单数据
- @param array $header 其他表单请求头
- @return array 上传结果

```php
     virtualFile($url,$files,$body=[],$header=[])
```


### 读取配置文件参数 - `ini`
- @param string $keys 配置路径
- @param string $def 默认值
- @return string|array 返回值

```php
ini(null) // 返回全部配置
ini('MYSQL','默认值') // 返回一级配置[数组]
ini('MYSQL.HOST') // 返回二级配置[字符串]
```
