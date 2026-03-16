<?php
// +----------------------------------------------------------------------
// | MICATEAM 
// +----------------------------------------------------------------------
// | Website: 
// +----------------------------------------------------------------------
// | Author:  <11247005@qq.com>
// +----------------------------------------------------------------------
use support\Db;
/**
 * 生成指定长度 - 用于验证码
 *
 * 使用Base32字符集：ABCDEFGHIJKLMNOPQRSTUVWXYZ234567
 *
 * @param int $len 长度
 *
 * @return string 不重复的验证码
 */
if (!function_exists('rand_base32')) {
    function rand_base32($len = 4)
    {
        return substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"), 0, $len);
    }
}
/**
 * 生成指定长度 - 用于密钥
 * 
 * 自行拼装 otpauth://totp/{label}?secret={secret}&issuer={issuer}
 * 
 * @param int|16 $len=16 可选：长度默认16
 * @param string|'' $prefix='' 可选：密钥前缀
 * 
 * @return string 返回密钥
 */
if (!function_exists('secret_base32')) {
    function secret_base32($len = 16, $prefix = '')
    {
        $char = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32字符集
        for ($i = 0; $i < $len; $i++) {
            $prefix .= $char[rand(0, strlen($char) - 1)];
        }
        return $prefix;
    }
}
/**
 * 基于时间的一次性密码 RFC6238
 *
 * Time-Based One-Time Password 
 * 
 * @param string $secret Base32编码的密钥
 * @param number|null $time 可选：时间戳。默认为null，表示当前时间。
 *
 * @return array 返回三组验证码
 *
 * @throws \Exception 无。
 *
 * 示例：
 * ```
 * // 将数据加入名为 'email_tasks' 的队列，无延迟
 * useTOTP('GQBWBS7AAEBECCUJ',1741877199);
 * [893277,448721,854850]
 * ```
 */
if (!function_exists('useTOTP')) {
    function useTOTP($secret, $time = null)
    {
        $secret = str_replace('=', '', strtoupper($secret));
        $time = floor(($time ?? time()) / 30);
        $char = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')); // Base32字符集
        $length = strlen($secret);
        $buffer = 0;
        $bits = 0;
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $buffer <<= 5;
            $buffer |= $char[$secret[$i]];
            $bits += 5;
            // 当累积的位数达到或超过8位时，处理这些位
            while ($bits >= 8) {
                $byte = ($buffer & (0xFF << ($bits - 8))) >> ($bits - 8);
                $key .= chr($byte);
                $bits -= 8;
            }
        }
        //
        $pool = [$time - 1, $time, $time + 1];
        foreach ($pool as &$item) {
            $item = pack('N*', 0) . pack('N*', $item);
            $hmac = hash_hmac('sha1', $item, $key, true);
            $offset = ord(substr($hmac, -1)) & 0xF;
            $code = (
                ((ord($hmac[$offset]) & 0x7F) << 24) |
                ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
                ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
                (ord($hmac[$offset + 3]) & 0xFF)
            ) % pow(10, 6);
            $item = str_pad($code, 6, '0', STR_PAD_LEFT);
        }
        return $pool;
    }
}
/**
 * Mysql实例 
 * 推荐使用 模型
 * [laravel数据库](https://github.com/illuminate/database)
 */
if (!function_exists('onMysql')) {
    function onMysql($table)
    {
        return Db::table($table);
    }
}
/*
 * Redis实例 - 配置文件读取默认
 * 返回句柄
 */
if (!function_exists('onRedis')) {
    function onRedis()
    {
        @['host' => $host, 'password' => $password, 'port' => $port] = config('redis.default');
        $redis = new Redis();
        $redis->connect($host, $port);
        $password && $redis->auth($password);
        return $redis;
    }
}
/*
 * Redis数组执行(自动close)
 * runRedis('HGET',["wallet",$uuid])
 * runRedis('HINCRBY',["wallet",$uuid,10])
 */
if (!function_exists('runRedis')) {
    function runRedis($method, $params = [])
    {
        @['host' => $host, 'password' => $password, 'port' => $port] = config('redis.default');
        $redis = new Redis();
        $redis->connect($host, $port);
        $password && $redis->auth($password);
        $data = $redis->$method(...$params);
        $redis->close();
        return $data;
    }
}
/*
 * [webman-queue] 队列封装
 * @param string $queue 队列名称
 * @param array $data 数据
 * @param int|null $delay 可选：延迟时间
 */
if (!function_exists('onQueue')) {
    function onQueue($queue, $data, $delay = 0)
    {
        $queue_waiting = '{redis-queue}-waiting';
        $queue_delay = '{redis-queue}-delayed';
        $now = time();
        $package_str = json_encode([
            'id' => rand(),
            'time' => $now,
            'delay' => $delay,
            'attempts' => 0,
            'queue' => $queue,
            'data' => $data
        ]);
        return $delay ? onRedis()->zAdd($queue_delay, $now + $delay, $package_str) : onRedis()->lPush($queue_waiting . $queue, $package_str);
    }
}
/*
 * [http-client] 异步网络请求封装
 * @param string $url 请求网址
 * @param array $options 请求配置
 * 示例：'https://example.com/', ['method' => 'POST','version' => '1.1','headers' => ['Connection' => 'keep-alive'],'data' => ['key1' => 'value1', 'key2' => 'value2'],]
 * 请求返回 返回 [JOSN] 非对象返回 [raw=响应内容]
 * 错误返回 ['err'=>500,'msg'=>'错误信息']
 */
if (!function_exists('onRequest')) {
    function onRequest($url, $options = [])
    {
        static $http;
        $http || $http = new Workerman\Http\Client([
            'max_conn_per_addr' => 128, // 每个域名最多维持多少并发连接
            'keepalive_timeout' => 15,  // 连接多长时间不通讯就关闭
            'connect_timeout' => 30,  // 连接超时时间
            'timeout' => 30,  // 请求发出后等待响应的超时时间
        ]);
        try {
            $response = $http->request($url, array_merge(['method' => 'GET', 'version' => '1.1'], $options));
            $boby = (string) $response->getBody();
            return json_decode($boby, true) ?? ['raw' => $boby];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage];
        }
    }
}
/*
 * HTTP代理网络请求 - 配置文件随机读取 [youloge.proxy[0~n]]
 * 请求参数与 httpProxy == onRequest == http-client(request) 一样
 * @param string $url 请求网址
 * @param array $options 请求配置
 */
if (!function_exists('httpProxy')) {
    function httpProxy($url, $options = [])
    {
        try {
            @['method' => $method, 'headers' => $headers, 'data' => $data] = $options;
            $proxy = config('youloge.proxy');
            $is_list = array_is_list($proxy);
            $is_list && shuffle($proxy);
            @[['addr' => $addr, 'port' => $port, 'pass' => $pass]] = $is_list ? $proxy : [$proxy];
            $method = strtoupper($method ?? 'GET');
            $headed = ['Connection: keep-alive'];
            // 处理头信息
            if (is_object($headers)) {
                foreach ($headers as $key => $value) {
                    $headed[] = "$key: $value";
                }
            } elseif (is_array($headers)) {
                $headed = array_merge($headed, $headers);
            }
            //
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                // 代理配置
                CURLOPT_PROXY => $addr,
                CURLOPT_PROXYPORT => $port,
                CURLOPT_PROXYUSERPWD => $pass,
            ]);
            if ($method == 'POST') {
                curl_setopt($curl, CURLOPT_POST, true);
                $data && curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            $response = curl_exec($curl);
            curl_close($curl);
            return json_decode($response, true) ?? ['raw' => $response];
        } catch (\Throwable $th) {
            return ['err' => 500, 'msg' => $th->getMessage()];
        }
    }
}
/**
 * 生成虚拟文件对象并上传 - 支持多文件
 * @param string $url 上传地址
 * @param array $files 文件类型数据 ['表单名称'=>['name'=>'文件名称','mime'=>'文件类型','data'=>'数据内容']]
 * @param array $body 其他表单数据
 * @param array $header 其他表单请求头
 * @return array 上传结果
 */
if (!function_exists('virtualFile')) {
    function virtualFile($url, $files, $body = [], $header = [])
    {
        try {
            $headers = ['Content-Type: multipart/form-data'];
            $form = array_merge([], $body);
            $temps = [];
            if (is_object($header)) {
                foreach ($header as $key => $value) {
                    $headers[] = "$key: $value";
                }
            } else {
                $headers = array_merge($headers, $header);
            }
            // 生成文件
            foreach ($files as $key => ['name' => $name, 'mime' => $mime, 'data' => $data]) {
                $temp = tmpfile();
                @['uri' => $uri] = stream_get_meta_data($temp);
                fwrite($temp, $data);
                $form[$key] = curl_file_create($uri, $mime, $name);
                $temps[] = $temp;
            }
            // 发送请求
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_POST => true,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_POSTFIELDS => $form
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            // 关闭临时文件
            foreach ($temps as $temp) {
                fclose($temp);
            }
            return json_decode($response, true) ?? ['raw' => $response];
        } catch (\Throwable $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
/**
 * =============================
 * = 算法相关
 * =============================
 */
/**
 * 安全的base64编码
 */
if (!function_exists('safe_base64_encode')) {
    function safe_base64_encode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
if (!function_exists('safe_base64_decode')) {
    function safe_base64_decode($data)
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
/**
 * 构造腾讯云请求体 - 配置路径(一律小写)：[youloge.{appid}.secretid|secretkey]
 * 签名方法：TC3-HMAC-SHA256
 * @param string $method  请求方式 GET/POST
 * @param string $endpoint_action_version_region  接入点/方法/版本/区域 
 * trtc.tencentcloudapi.com/DescribeInstances/2019-07-22/ap-guangzhou
 * @param array $payload  请求载体 无参数时 设为[],null,false,0 即可
 * @param string $appid  选择那个商户id下得的证书
 */
if (!function_exists('tencent_request')) {
    function tencent_request($method, $endpoint_action_version_region, $payload, $appid)
    {
        @['secretid' => $SecretId, 'secretkey' => $SecretKey] = config("youloge.$appid");
        @[$Endpoint, $Action, $Version, $Region] = $tencent = explode('/', $endpoint_action_version_region);
        @[$Server] = explode('.', $Endpoint);
        $data = $payload ? json_encode($payload, 320) : '';
        $method = strtoupper($method);
        // 准备参数
        $Timestamp = time();
        $Timesdate = gmdate("Y-m-d", $Timestamp);
        $body_h256 = hash('SHA256', $data);
        // 第一步
        $request_h256 = hash('SHA256', "$method\n/\n\ncontent-type:application/json\nhost:$Endpoint\n\ncontent-type;host\n$body_h256");
        // 第二步
        $StringToSign = "TC3-HMAC-SHA256\n$Timestamp\n$Timesdate/$Server/tc3_request\n$request_h256";
        // 第三步
        $SecretDate = hash_hmac('SHA256', $Timesdate, "TC3$SecretKey", true);
        $SecretService = hash_hmac('SHA256', $Server, $SecretDate, true);
        $SecretSigning = hash_hmac('SHA256', "tc3_request", $SecretService, true);
        $Signature = hash_hmac('SHA256', $StringToSign, $SecretSigning);
        // 第四步
        $Authorization = "TC3-HMAC-SHA256 Credential=$SecretId/$Timesdate/$Server/tc3_request, SignedHeaders=content-type;host, Signature=$Signature";
        $header = [
            "Authorization" => "$Authorization",
            "Content-Type" => "application/json", // ; charset=utf-8
            "X-TC-Action" => "$Action",
            "X-TC-Version" => "$Version",
            "X-TC-Timestamp" => "$Timestamp"
        ];
        $Region && $header['X-TC-Region'] = $Region;
        // 第五步
        return [
            "https://$Endpoint",
            [
                'method' => $method,
                'headers' => $header,
                'data' => $data,
            ]
        ];
    }
}
/**
 * 七牛签名 - 配置文件读取[youloge.qiniu.ak/sk]
 * qiniu_hmac 
 * 
 */
/**
 * 七牛HMAC - 
 * @param string $string 待签名字符串
 */
if (!function_exists('qiniu_hmac')) {
    function qiniu_hmac($string)
    {
        @['ak' => $AK, 'sk' => $SK] = config('youloge.qiniu');
        $sign = str_replace(['+', '/'], ['-', '_'], base64_encode(hash_hmac('sha1', $string, $SK, true)));
        return "$AK:$sign";
    }
}
/**
 * 七牛SIGN - 
 * @param array $params 待签名数组对象
 */
if (!function_exists('qiniu_sign')) {
    function qiniu_sign($params)
    {
        $string = str_replace(['+', '/'], ['-', '_'], base64_encode(json_encode($params)));
        $sign = qiniu_hmac($string);
        return "$sign:$string";
    }
}
/**
 * 七牛AUTH - 
 * @param array $params 待签名数组对象
 */
if (!function_exists('qiniu_auth')) {
    function qiniu_auth($params,$ContentType = "application/json")
    {
        @['ak' => $ak, 'sk' => $sk] = config('youloge.qiniu');
        $string = str_replace(['+', '/'], ['-', '_'], base64_encode($params));
        $sign = str_replace(['+', '/'], ['-', '_'], base64_encode(hash_hmac('sha1', $string, $sk, true)));
        return ["Authorization: Qiniu $ak:$sign", "Content-Type: $ContentType"];
    }
}
/**
 * 七牛DOWN - 
 * @param array $url 待签名下载网址
 * @param number $second 可选：设置有效时间 默认3600秒
 * @param string $attname 可选：设置下载文件名 默认没有
 */
if (!function_exists('qiniu_download')) {
    function qiniu_download($url, $second = 3600, $attname = '')
    {
        @['scheme' => $scheme, 'host' => $host, 'path' => $path, 'query' => $queryString] = parse_url($url);
        $queryString && parse_str($queryString, $query);
        $query['e'] = time() + $second;
        $uri = sprintf("%s://%s%s", $scheme, $host, $path);
        $query['token'] = qiniu_hmac(sprintf('%s?%s', $uri, http_build_query($query)));
        $attname && $query['attname'] = $attname;
        return $uri . '?' . http_build_query($query);
    }
}

/**
 * =============================
 * = 支付算法类 详细配置文件
 * = 证书路径 youloge.{$appid}.{apiclient_key}
 * = 证书格式 1. ./file.pem 文件路径 PEM编码的证书/私钥|公钥 2. PEM格式的私钥|公钥
 * =============================
 */
/***
 * 
 * 私钥签名 - 配置路径：[youloge.{appid}.apiclient_key]
 * @param string $string 待签名字符串
 * @param string $appid 选择那个id下得的证书
 * 返回数组 成功 [err=>200,data=>base64] 失败 [err=>500,msg=>'签名错误']
 */
if (!function_exists('private_sign')) {
    function private_sign($string, $appid)
    {
        try {
            @['apiclient_key' => $apiclient_key] = config("youloge.$appid");
            openssl_sign($string, $raw_sign, openssl_pkey_get_private($apiclient_key), 'sha256WithRSAEncryption');
            return ['err' => 200, 'data' => base64_encode($raw_sign)];
        } catch (\Throwable $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
/***
 * 构造微信支付请求体 - 配置路径：[youloge.{appid}.apiclient_key|serial_no...]
 * 示例：weixin_request('GET','/v3/certificates',{},11111111);
 * 
 * @param string $method 请求网络方式 GET/POST
 * @param string $router 请求网络路径 必须'/'开头
 * @param array $data JSON数据 不传设置为 '' false 0 即可
 * @param string $appid 选择那个商户id下得的证书
 */
if (!function_exists('weixin_request')) {
    function weixin_request($method, $router, $data = '', $appid = '')
    {
        @['apiclient_key' => $apiclient_key, 'serial_no' => $serial_no] = config("youloge.$appid");
        $noncestr = session_create_id();
        $timestamp = (string) time();
        $body = $data ? json_encode($data, 320) : '';
        $method = strtoupper($method);

        openssl_sign("$method\n$router\n$timestamp\n$noncestr\n$body\n", $raw_sign, openssl_pkey_get_private($apiclient_key), 'sha256WithRSAEncryption');
        $sign = base64_encode($raw_sign);
        $authorization = sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%d",serial_no="%s"', $appid, $noncestr, $sign, $timestamp, $serial_no);

        $header = ['accept' => 'application/json', 'authorization' => $authorization, 'User-Agent' => 'https://zh.wikipedia.org/wiki/User_agent', 'Content-Type' => 'application/json'];
        // 返回请求体
        return [sprintf('https://api.mch.weixin.qq.com%s', $router), ['method' => $method, 'headers' => $header, 'data' => $body]];
    }
}
/**
 * 微信回调验签 - 配置路径：[youloge.{serial}.platform_cert]
 * @param object $request Request 给返回对象传进来
 * 成功返回 对象返回JSON 否则返回 []
 * 失败返回 ['err'=>500,'msg'=>Exception]
 */
if (!function_exists('weixin_verify')) {
    function weixin_verify($request)
    {
        try {
            @['Wechatpay-Timestamp' => $Timestamp, 'Wechatpay-Nonce' => $Nonce, 'Wechatpay-Signature' => $Signature, 'Wechatpay-Serial' => $Serial] = $request->header();
            @['platform_cert' => $platform_cert] = config("youloge.$Serial");
            $rawBody = $request->getContent();
            $verify = (bool) openssl_verify("$Timestamp\n$Nonce\n$rawBody\n", base64_decode($Signature), openssl_get_publickey($platform_cert), 'sha256WithRSAEncryption');
            return $verify ? $request->all() : [];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
/**
 * 微信解密V3 - 配置路径：[youloge.{mchid}.v3key]
 * @param array $encrypt 解密数据 要有['ciphertext','nonce','associated_data'] 
 * @param string $mchid 选择那个商户id下得的证书
 * 成功返回 对象返回JSON 否则返回 ['raw'=>$raw]
 * 失败返回 ['err'=>500,'msg'=>Exception]
 */
if (!function_exists('weixin_decrypt')) {
    function weixin_decrypt($encrypt, $mchid)
    {
        try {
            @['v3key' => $v3key] = config("youloge.$mchid");
            @['ciphertext' => $ciphertext, 'nonce' => $nonce, 'associated_data' => $associated] = $encrypt;
            $cipher = base64_decode($ciphertext);
            $decrypt = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', $v3key, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16), $associated);
            return json_decode($decrypt, true) ?? ['raw' => $decrypt];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}

/**
 * 构造支付宝支付请求体 - 配置路径：[appid.{appid}.apiclient_key]
 * 示例：alipay_request('alipay.trade.create',$data,11111111);
 * @param string $method  接口名称 alipay.trade.create ...
 * @param array $data  待合并参数
 * @param string $appid  选择那个商户id下得的证书
 */
if (!function_exists('alipay_request')) {
    function alipay_request($method, $data, $appid)
    {
        @['public' => $public, $method => $params] = config('youloge.alipay');
        @['apiclient_key' => $apiclient_key] = config("youloge.$appid");
        $body = array_merge($public, $params ?? [], $data ?? [], ['app_id' => $appid, 'method' => $method]);
        ksort($body);
        openssl_sign(urldecode(http_build_query($body)), $raw_sign, openssl_pkey_get_private($apiclient_key), 'sha256WithRSAEncryption');
        $body['sign'] = base64_encode($raw_sign);
        ;
        return [
            sprintf("https://openapi.alipay.com/gateway.do?%s", http_build_query($body)),
            [
                'method' => 'GET',
                'version' => '1.1',
                'headers' => ['accept' => 'application/json, text/plain, */*'],
                // 'data' => $body,
            ]
        ];
    }
}
/**
 * 支付宝验签 - 配置路径：[youloge.alipay.public_key]
 * @param object $request Request 给返回对象传进来
 * 成功返回 对象返回JSON 否则返回 []
 * 失败返回 ['err'=>500,'msg'=>Exception]
 */
if (!function_exists('alipay_verify')) {
    function alipay_verify($request)
    {
        try {
            @['public_key' => $alipay_public_key] = config('youloge.alipay');
            @['sign' => $sign, 'sign_type' => $sign_type] = $params = $request->all();
            unset($params['sign']);
            unset($params['sign_type']);
            ksort($params);
            $verify = (bool) openssl_verify(urldecode(http_build_query($params)), base64_decode($sign), openssl_get_publickey($alipay_public_key), 'sha256WithRSAEncryption');
            return $verify ? $params : [];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
/**
 * 读取配置文件参数
 * `ini(null)`返回全部配置 
 * `ini('MYSQL','默认值')` 返回一级配置[数组]
 * `ini('MYSQL.HOST')` 返回三级配置[字符串]
 */
if (!function_exists('ini')) {
    function ini($keys, $def = '')
    {
        static $config = [];
        if (!$config) {
            $path = Phar::running() ? dirname(Phar::running(false)) : base_path();
            $config = @parse_ini_file($path . '/.env', true) ?? [];
        }
        if ($keys === null) {
            return $config;
        }
        @[$one, $two] = explode('.', $keys);
        @[$one => $item] = $config;
        return $two === null ? $item ?? $def : $item[$two] ?? $def;
    }
}
/**
 * 扩展方法 
 * 用于兼容 (PHP 8 <= 8.1.0) 7.2+
 * 判断是否可循环数组
 */
if (!function_exists('array_is_list')) {
    function array_is_list($arg)
    {
        return $arg === [] || (array_keys($arg) === range(0, count($arg) - 1));
    }
}
/**
 * 验证和处理表单数据
 *
 * 可以通过多个调用方式 实现复杂处理
 *
 * @param object $params 表单数据
 * @return object $rules 验证规则
 * @return bool $intersect 是否只返回验证通过的数据
 * @return array $result 验证结果
 * @throws Exception 验证失败抛出异常 ['err'=>400,'msg'=>'错误提示']
 * @example
 */

if (!function_exists('useValidate')) {
    function useValidate($params, $rules, $intersect = true)
    {
        $presets = [
            // 基本处理
            'require' => function ($field, $param, $args, $message = '%s 字段不能为空') {
                // 1. 检查参数是否存在（未提交或值为 null 则视为缺失）
                if (!isset($param) || $param === null) {
                    throw new Exception(sprintf($message, $field));
                }
                // 2. 处理字符串类型：排除纯空格（如 '   ' 应视为空）
                if (is_string($param) && trim($param) === '') {
                    throw new Exception(sprintf($message, $field));
                }
                // 3. 其他情况（如 0、'0'、false、数组等）视为有效
                return $param;
            },
            'required' => function ($field, $param, $args, $message = '%s 字段不能为空') {
                // 1. 检查参数是否存在（未提交或值为 null 则视为缺失）
                if (!isset($param) || $param === null) {
                    throw new Exception(sprintf($message, $field));
                }
                // 2. 处理字符串类型：排除纯空格（如 '   ' 应视为空）
                if (is_string($param) && trim($param) === '') {
                    throw new Exception(sprintf($message, $field));
                }
                // 3. 其他情况（如 0、'0'、false、数组等）视为有效
                return $param;
            },
            'int' => function ($field, $param, $args, $msg = '') {
                return (int) ($param ?? $args);
            },
            'bool' => function ($field, $param, $args, $msg = '') {
                return (bool) ($param ?? $args);
            },
            'float' => function ($field, $param, $args, $msg = '') {
                return (float) ($param ?? $args);
            },
            'string' => function ($field, $param, $args, $msg = '') {
                return (string) ($param ?? $args);
            },
            'array' => function ($field, $param, $args, $msg = '') {
                return (array) ($param ?? (json_decode("[$args]",true)));
            },
            'object' => function ($field, $param, $args, $msg = '') {
                // return [$field, $param, $args, $msg];
                return (object) ($param ?? (json_decode("{{$args}}",false)));
            },
            'sprintf' => function ($field, $param, $args = '', $msg = '') {
                return sprintf($args, $param);
            },
            'format' => function ($field, $param, $args = '', $msg = '') {
                return sprintf($args, $param);
            },
            // 常用处理
            'xss' => function ($field, $param, $args, $msg = '') {
                $replace = str_replace(["'", '"', ';', '--', '%', '_', '(', ')'], '', $param);
                return strip_tags($replace, $args);
            },
            'html' => function ($field, $param, $args, $msg = '') {
                return htmlspecialchars($param, $args ?? (ENT_COMPAT | ENT_HTML401));
            },
            'join' => function ($field, $param, $args, $msg = '') {
                return implode($args ?? ',', $param);
            },
            'trim' => function ($field, $param, $args, $msg = '') {
                return trim((string) $param) ?? $args;
            },
            'upper' => function ($field, $param, $args, $msg = '') {
                return strtoupper($param) ?? $args;
            },
            'lower' => function ($field, $param, $args, $msg = '') {
                return strtolower($param) ?? $args;
            },
            // 常用验证
            'email' => function ($field, $param, $args, $msg = '%s 字段值必须是邮箱') {
                if (filter_var($param, FILTER_VALIDATE_EMAIL)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'mobile' => function ($field, $param, $args, $msg = '%s 字段值必须是手机号') {
                $options = [
                    'options' => [
                        'regexp' => "/^1[3456789]\d{9}$/"
                    ]
                ];
                if (filter_var($param, FILTER_VALIDATE_REGEXP, $options)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'url' => function ($field, $param, $args, $msg = '%s 字段值必须是网址') {
                if (filter_var($param, FILTER_VALIDATE_URL)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'ip' => function ($field, $param, $args, $msg = '%s 字段值必须是IP地址') {
                if (filter_var($param, FILTER_VALIDATE_IP)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'date' => function ($field, $param, $args, $msg = '%s 字段值必须是%s日期格式') {
                $format = $args ?? 'Y-m-d H:i:s';
                $dateTime = DateTime::createFromFormat($format, $param);
                if ($dateTime && !$dateTime->getLastErrors()['warning_count']) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $format));
            },
            'time' => function ($field, $param, $args, $msg = '%s 字段值必须是时间戳') {
                if (!is_numeric($param) || intval($param) != $param) {
                    throw new Exception(sprintf($msg, $field));
                }
                $minTimestamp = strtotime('1970-01-01'); // 0
                $maxTimestamp = strtotime('2099-01-01'); // 4070908800000 32位是2038-01-19
                if ($param <= $minTimestamp || $param >= $maxTimestamp) {
                    throw new Exception(sprintf($msg, $field));
                }
                return $param;
            },
            'idcard' => function ($field, $param, $args, $msg = '%s 字段值必须是身份证号 %s') {
                if (strlen($param) !== 18) {
                    throw new Exception(sprintf($msg, $field, '长度不足'));
                }
                if (preg_match('/^\d{17}[\dXx]$/', $param) == false) {
                    throw new Exception(sprintf($msg, $field, '格式错误'));
                }
                // 加权因子
                $weightFactors = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
                // 校验码映射
                $checkCodes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
                // 计算校验码
                $sum = 0;
                for ($i = 0; $i < 17; $i++) {
                    $sum += intval($param[$i]) * $weightFactors[$i];
                }
                $mod = $sum % 11;
                $checkCode = $checkCodes[$mod];
                if (strtoupper($param[17]) !== $checkCode) {
                    throw new Exception(sprintf($msg, $field, '校验错误'));
                }
                return $param;
            },
            'regex' => function ($field, $param, $args, $msg = '%s 字段值格式错误') {
                if (preg_match($args, $param) === false) {
                    throw new Exception(sprintf($msg, $field));
                }
                return $param;
            },
            'test' => function ($field, $param, $args, $msg = '%s 字段值格式错误') {
                if (preg_match($args, $param) === false) {
                    throw new Exception(sprintf($msg, $field));
                }
                return $param;
            },
            //数字相关
            'min' => function ($field, $param, $args, $msg = '%s 字段数字不能小与%s') {
                $min = min(explode(',', $args));
                if (is_numeric($param) && $param >= $min) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $min));
            },
            'max' => function ($field, $param, $args, $msg = '%s 字段数字不能大于%s') {
                $max = max(explode(',', $args));
                if (is_numeric($param) && ($param <= $max)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $max));
            },
            'between' => function ($field, $param, $args, $msg = '%s 字段数字必须在%s和%s之间') {
                $conf = explode(',', $args);
                $min = min($conf);
                $max = max($conf);
                if (is_numeric($param) && $param >= $min && $param <= $max) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $min, $max));
            },
            // 字符串相关
            'start' => function ($field, $param, $args, $msg = '%s 字段值必须以%s开头') {
                // throw new Exception((string)$param);
                if (str_starts_with($param, $args)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $args));
            },
            'end' => function ($field, $param, $args, $msg = '%s 字段值必须以%s结尾') {
                if (str_ends_with($param, $args)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $args));
            },
            'digit' => function ($field, $param, $args, $msg = '%s 字段值必须是数字') {
                if (ctype_digit($param)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'alpha' => function ($field, $param, $args, $msg = '%s 字段值必须是字母') {
                if (ctype_alpha($param)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'alphanum' => function ($field, $param, $args, $msg = '%s 字段值必须是字母和数字') {
                if (ctype_alnum($param)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field));
            },
            'length' => function ($field, $param, $args, $msg = '%s 字段长度必须%s~%s个字符') {
                $conf = explode(',', $args);
                $min = min($conf);
                $max = max($conf);
                $len = mb_strlen($param);
                if ($len >= $min && $len <= $max) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $min, $max));
            },
            'len' => function ($field, $param, $args, $msg = '%s 字段长度必须%s~%s个字符') {
                $conf = explode(',', $args);
                $min = min($conf);
                $max = max($conf);
                $len = mb_strlen($param);
                if ($len >= $min && $len <= $max) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $min, $max));
            },
            'in' => function ($field, $param, $args, $msg = '%s 字段值必须在%s范围中') {
                $conf = explode(',', $args);
                if (in_array($param, $conf)) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $args));
            },
            'not' => function ($field, $param, $args, $msg = '%s 字段值不能在%s范围中') {
                $conf = explode(',', $args);
                if (in_array($param, $conf) == false) {
                    return $param;
                }
                throw new Exception(sprintf($msg, $field, $args));
            },
            'count' => function ($field, $param, $args, $message = '%s 字段值数量必须%s~%s个') {
                $conf = explode(',', $args);
                $min = min($conf);
                $max = max($conf);

                if (is_array($param) && count($param) >= $min && count($param) <= $max) {
                    return $param;
                }
                throw new Exception(sprintf($message, $field, $min, $max));
            }
        ];
        // 递归处理
        try {
            foreach ($rules as $field => $rule) {
                // 初始化当前字段值
                @[$field=>$param] = $params;
                // 1. 处理可迭代规则（数组类型规则）
                if(is_iterable($rule)){
                    // 1.1 索引数组：流水线/数组元素遍历
                    if(array_is_list($rule)){
                        if(count($rule) == 1){
                            if (!is_array($param)) {
                                throw new Exception("{$field}：必须是一维数组");
                            }
                            // 值遍历
                            foreach ($params[$field] as $index => $paraming) {
                                @['err'=>$err,'msg'=>$msg,$index=>$callback] = $back = useValidate([$index => $paraming],[$index => $rule[0]],$intersect);
                                if($err === 400){ throw new Exception("$msg"); }
                                $params[$field][$index] = $callback;
                            }
                        // 流水线规则遍历
                        }else{
                            foreach($rule as $_rule){
                                @[$field=>$current] = $params;
                                @['err'=>$err,'msg'=>$msg,$field=>$callback] = $back = useValidate([$field=>$current],[$field=>$_rule],$intersect);
                                if($err === 400){ throw new Exception("$field.$msg"); }
                                $params[$field] = $callback;
                            }
                        }

                    // 1.2 关联数组：单个对象规则（子字段数据流转）
                    }else{
                        @['err'=>$err,'msg'=>$msg] = $callback = useValidate($param, $rule, $intersect);
                        if($err === 400){ throw new Exception("$field.$msg"); }
                        $params[$field] = $callback;
                    }
                    continue;
                }
                // 闭包回调规则
                if(is_callable($rule)){
                    try {
                    // 闭包返回的预处理结果，作为后续规则的输入
                        $params[$field] = $rule($field, $param);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage(),400);
                    }
                    continue;
                }
                // 2. 处理字符串规则（单规则/多规则组合） expression
                if(is_string($rule)){
                    @[$expression, $customMsg] = explode('#', $rule);
                    $pipeline = explode('|', $expression);
                    $required = str_contains($expression,'required') || str_contains($expression,'require');
                    foreach ($pipeline as $step) {
                        @[$ruleName, $ruleParam] = explode(':', $step, 2);
                        @[$ruleName=>$method] = $presets;
                        // 非必填且值为null，或规则不存在时跳过（支持默认值）
                        if(($param === null && $required === false) || $method === null){
                            // 类型转换规则的默认值处理
                            if(in_array($ruleName,['int','bool','float','string','array','object']) && !is_null($ruleParam)){
                                $param = $method($field,$param,$ruleParam); // 更新param值，确保后续规则能使用
                                $params[$field] = $param;
                            }
                            continue; 
                        }
                        // 执行验证规则
                        $args = [$field, $param, $ruleParam];
                        if($customMsg){
                            array_push($args, $customMsg);
                        }
                        $param = $method(...$args);
                        $params[$field] = $param;
                        // 更新param为最新值，支持规则流转（前一个规则结果作为后一个输入）
                    }
                }
            }
            return $intersect ? array_intersect_key($params,$rules) : $params;
        }catch (Exception $e) {
            return ['err' => 400, 'msg' => $e->getMessage()];
        }
    }
}
