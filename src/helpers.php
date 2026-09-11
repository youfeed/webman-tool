<?php
// +----------------------------------------------------------------------
// | MICATEAM 
// +----------------------------------------------------------------------
// | Website: docs.youloge.com
// +----------------------------------------------------------------------
// | Author:  <11247005@qq.com>
// +----------------------------------------------------------------------
use support\Db;
use support\Redis;

if (!function_exists('useLock')) {
    /**
     * Redis排它锁
     * @param string $key 
     * @param int|string $param 默认10 传秒数加锁 字符串解锁(只能解锁自己的锁)
     * @return bool|string 加锁成功返回token,失败false 解锁是返回bool
     */
    function useLock($key, $param = 10)
    {
        $keys = "Lock:$key";
        if (is_int($param)) {
            $token = uniqid('', true);
            return (Redis::set($keys, $token, 'EX', $param, 'NX') ? $token : false);
        }
        if (is_string($param)) {
            $lua = <<<'LUA'
                local v = redis.call('GET',KEYS[1])
                if v == ARGV[1] then
                    return redis.call('DEL',KEYS[1])
                else
                    return 0
                end
            LUA;
            return (Redis::eval($lua, 1, $keys, $param) === 1);
        }
        return false;
    }
}
if (!function_exists('useCache')) {
    /**
     * Redis缓存读写与自增器
     * @param string $key 
     * @param string|array $params 默认read 模式参数 read=读取 once=读并删除 incr=计数器自增 ttl=查看剩余有效期
     * @param int $expire=300  写入时为缓存有效期; incr模式下代表自增步长(默认步长1)
     */
    function useCache($key, $params = 'read', $expire = 300)
    {
        $keys = "Cache:$key";
        return match ($params) {
            'ttl' => Redis::ttl($keys),
            'read' => json_decode(Redis::get($keys) ?? '[]', true) ?? [],
            'once' => json_decode(Redis::getDel($keys) ?? '[]', true) ?? [],
            'incr' => Redis::incrBy($keys, $expire === 300 ? 1 : $expire),
            default => Redis::set($keys, json_encode($params, 320), 'EX', $expire)
        };
    }
}
if (!function_exists('useLimit')) {
    /**
     * 固定|滑动 窗口限速器-N秒内尝试N次
     * @param string $key 限速键名
     * @param int $limit 限速次数
     * @param int $ttl 过期时间
     * @param int $locking 锁定时间 >0 则为滑动窗口锁定模式
     * @return bool true=放行 false=超限
     */
    function useLimit($key, $limit = 1, $ttl = 60, $locking = 0)
    {
        $lua = $locking ? trim(<<<'LUA'
            local zKey = KEYS[1]
            local lKey = KEYS[2]
            local now = tonumber(ARGV[1])
            local window = tonumber(ARGV[2])
            local maxCnt = tonumber(ARGV[3])
            local lockTtl = tonumber(ARGV[4])
            -- 已锁定直接拒绝
            if redis.call("EXISTS", lKey) == 1 then
                return 0
            end
            -- 清理过期记录
            redis.call("ZREMRANGEBYSCORE", zKey, 0, now - window)
            -- 获取当前计数
            local count = tonumber(redis.call("ZCARD", zKey))
            if count >= maxCnt then
                -- 超过限制，设置锁定键
                redis.call("SETEX", lKey, lockTtl, 1)
                return 0
            else
                -- 添加当前请求时间戳到有序集合
                redis.call("ZADD", zKey, now, now)
                redis.call("EXPIRE", zKey, window)
                return 1
            end
        LUA) : trim(<<<'LUA'
            local key = KEYS[1]
            local limit = tonumber(ARGV[1])
            local ttl = tonumber(ARGV[2])
            -- 直接进行自增
            local cnt = redis.call("INCR", key)
            -- 第一次计数，设置过期时间
            if cnt == 1 then
                redis.call("EXPIRE", key, ttl)
            end
            -- 超过上限返回0 否则1
            return cnt > limit and 0 or 1
        LUA);
        // eval(脚本, KEYS数量, ARGV1, ARGV2, ARGV3)
        return $locking ? Redis::eval($lua, 2, "limits:{$key}", "limits:{$key}.lock", time(), $ttl, $limit, $locking) : Redis::eval($lua, 1, "limit:{$key}", $limit, $ttl, $locking);
    }
}
if (!function_exists('useIncrBy')) {
    /**
     * UUID自增编码器
     * @param string $name 自增器名称
     * @param int $step 自增步长
     * @return int 返回自增数字
     */
    function useIncrBy(string $name, int $step = 1)
    {
        return Redis::hIncrBy("Youloge:UUID", $name, $step);
    }
}
if (!function_exists('useAuthenticator')) {
    /**
     * 使用Authenticator二次验证器：只支持TOTP
     * 
     * Authenticator 二次验证器
     * @param int|string $secret 密钥|{label}|null
     * @param int|string $params 验证码长度
     * @return string 
     * @return string 返回验证码
     * RFC4648 base32，Authenticator标准字符集 ABCDEFGHIJKLMNOPQRSTUVWXYZ234567
     */
    function useAuthenticator($secret = null, $params = null)
    {
        $char = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32字符集
        // 生成 $secret = issuer:account
        if (str_contains($secret, ':')) {
            [$issuer, $account] = explode(':', $secret, 2);
            $length = is_int($params) ? $params : 16;
            for ($i = 0; $i < $length; $i++) {
                $secret .= $char[rand(0, strlen($char) - 1)];
            }
            $label = "$account:$issuer";
            return [
                'label' => "$label",
                'secret' => $secret,
                'issuer' => $issuer,
                'account' => $account,
                'link' => "otpauth://totp/$account:$issuer?secret=$secret&issuer=$issuer"
            ];
        }
        $secret = strtoupper(trim($secret));
        $base32Pattern = '/^[A-Z234567]+$/';
        if (!preg_match($base32Pattern, $secret)) {
            throw new \InvalidArgumentException('TOTP密钥非法', 102002);
        }
        // 

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
        $time = null;
        if ($params === null) {
            $time = floor(time() / 30);
        }
        if (is_int($params)) {
            $time = floor($time / 30);
        }
        // 生成3组 6位验证码
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
        return is_string($params) ? in_array($params, $pool) : $pool;
    }
}
/**
 * =============================
 * = 网络封装
 * =============================
 */
if (!function_exists('useQueue')) {
    /** 
     * 队列封装 [webman-queue](https://www.workerman.net/doc/workerman/components/workerman-queue.html)
     * @param string $queue 队列名称
     * @param array $data 数据
     * @param int|null $delay 可选：延迟时间
     */
    function useQueue($queue, $data, $delay = 0)
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
        return $delay ? Redis::zAdd($queue_delay, $now + $delay, $package_str) : Redis::lPush($queue_waiting . $queue, $package_str);
    }
}
if (!function_exists('useRequest')) {
    /**
     * 异步网络请求封装 [http-client](https://www.workerman.net/doc/workerman/components/workerman-http-client.html)
     * @param string $url 请求网址
     * @param array $options 请求配置
     * 示例：'https://example.com/', ['method' => 'POST','version' => '1.1','headers' => ['Connection' => 'keep-alive'],'data' => ['key1' => 'value1', 'key2' => 'value2'],]
     * 请求返回 返回 [JOSN] 非对象返回 [raw=响应内容]
     * 错误返回 ['err'=>500,'msg'=>'错误信息']
     */
    function useRequest($url, $options = [])
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
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('httpProxy')) {
    /**
     * HTTP代理网络请求 - 配置文件随机读取 [youloge.proxy[0~n]]
     * 请求参数与 httpProxy == onRequest == http-client(request) 一样
     * @param string $url 请求网址
     * @param array $options 请求配置
     * @param int $index 配置下标(默认0)
     */
    function httpProxy($url, $options = [], $index = 0)
    {
        try {
            @['method' => $method, 'headers' => $headers, 'data' => $data] = $options;
            $proxy = pluginConfig('proxy')[$index];
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
if(!function_exists('headRequest')){
    /**
     * 发起HEAD请求
     * @param string $url 完整URL
     * @param array $options 请求参数（如headers）
     * @return array 
     * 成功 成功 ['Headers'=>[], 'Request'=>[]]
     * 失败 ['err'=>500,'msg'=>'错误信息']
     */
    function headRequest($url,$options=[]){
        static $http;
        $http || $http = new Workerman\Http\Client([
            'max_conn_per_addr' => 128, // 每个域名最多维持多少并发连接
            'keepalive_timeout' => 15,  // 连接多长时间不通讯就关闭
            'connect_timeout' => 30,  // 连接超时时间
            'timeout' => 30,  // 请求发出后等待响应的超时时间
        ]);
        try {
            $response = $http->request($url, array_merge(['method' => 'HEAD', 'version' => '1.1'], $options));
            return ['Headers' => $response->getHeaders(), 'Request' => json_decode((string)$response->getBody(), true) ?? $response->getBody()];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
        
    }
}
if (!function_exists('virtualFile')) {
    /**
     * 生成虚拟文件对象并上传 - 支持多文件
     * @param string $url 上传地址
     * @param array $files 文件类型数据 ['表单名称'=>['name'=>'文件名称','mime'=>'文件类型','data'=>'数据内容']]
     * @param array $body 其他表单数据
     * @param array $header 其他表单请求头
     * @return array 上传结果
     */
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
if (!function_exists('apiMeilisearch')) {
    /**
     * Meilisearch API 请求
     * @param string $route 路由
     * @param array $params 参数
     * @param string $method 方法
     * @return array|string 返回数据
     */
    function apiMeilisearch($route, $params = [], $method = 'GET')
    {
        static $http;
        $http = $http ?: new Workerman\Http\Client();
        @['host' => $host, 'ak' => $ak] = pluginConfig('meilisearch');
        // 基础数据
        $options = [
            'method' => $method,
            'version' => '1.1',
            'data' => $method == 'GET' ? http_build_query($params) : json_encode($params, 320),
            'headers' => [
                "Accept" => "application/json",
                'Content-Type' => $method == 'GET' ? 'application/x-www-form-urlencoded' : 'application/json',
                'Authorization' => "Bearer $ak"
            ]
        ];
        // 请求数据 ?
        $url = $method == 'GET' ? "$host/$route?" . http_build_query($params) : "$host/$route";
        $data = (string) $http->request($url, $options)->getBody();
        return json_decode($data, true) ?? $data;
    }
}
if (!function_exists('vipMeilisearch')) {
    /**
     * Meilisearch 管理 API 请求
     * @param string $route 路由
     * @param array $params 参数
     * @param string $method 方法
     * @return array|string 返回数据
     */
    function vipMeilisearch($route, $params = [], $method = 'GET')
    {
        static $http;
        $http = $http ?: new Workerman\Http\Client();
        @['host' => $host, 'sk' => $sk] = pluginConfig('meilisearch');
        // 基础数据
        $options = [
            'method' => $method,
            'version' => '1.1',
            'data' => $method == 'GET' ? http_build_query($params) : json_encode($params, 320),
            'headers' => [
                "Accept" => "application/json",
                'Content-Type' => $method == 'GET' ? 'application/x-www-form-urlencoded' : 'application/json',
                'Authorization' => "Bearer $sk",
            ]
        ];
        // 请求数据
        $data = (string) $http->request("$host/$route", $options)->getBody();
        return json_decode($data, true) ?? $data;
    }
}
/**
 * =============================
 * = 算法相关
 * =============================
 */
if(!function_exists('useBase32')){
    /**
     * 生成指定长度Base32字符
     * @param int $len 长度 默认5
     * @return string Base32字符串
     */
    function useBase32($len=5){
        return substr(str_shuffle("23456789ABCDEFGHJKLMNPQRSTUVWXYZ"), 0, $len);
    }
}
if (!function_exists('useBase58')) {
    /**
     * Base58编码解码 - 压缩数字
     * @param int|string $input int：编码数字 string：解码字符串
     * @param int $digits 随机混淆盐位数，默认3
     * @return int|string 编码数字|解码字符串
     */
    function useBase58($input, $digits = 3)
    {
        $origin = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        // 内部生成salt
        $base58_salt = function (int $len): string {
            $saltTable = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
            $out = '';
            $max = strlen($saltTable) - 1;
            for ($i = 0; $i < $len; $i++) {
                $out .= $saltTable[random_int(0, $max)];
            }
            return $out;
        };
        // 数字 → encode编码
        if (is_int($input)) {
            $number = $input;
            $chars = str_split($origin);
            $salt = $base58_salt($digits);
            if ($salt !== '') {
                $seed = crc32($salt);
                mt_srand($seed);
                shuffle($chars);
            }
            $table = implode('', $chars);

            $val = gmp_init($number);
            $result = '';
            do {
                [$val, $mod] = gmp_div_qr($val, 58);
                $result = $table[gmp_intval($mod)] . $result;
            } while (gmp_cmp($val, 0) > 0);

            return $salt . ($result === '' ? $table[0] : $result);
        }
        // 字符串 → decode解码
        if (is_string($input)) {
            $str = $input;
            $salt = substr($str, 0, $digits);
            $code = substr($str, $digits);

            $chars = str_split($origin);
            $seed = crc32($salt);
            mt_srand($seed);
            shuffle($chars);
            $table = implode('', $chars);

            $val = gmp_init(0);
            $len = strlen($code);
            for ($i = 0; $i < $len; $i++) {
                $c = $code[$i];
                $pos = strpos($table, $c);
                $val = gmp_add(gmp_mul($val, 58), $pos);
            }
            return gmp_intval($val);
        }
        throw new InvalidArgumentException('输入只支持 int(编码) / string(解码)');
    }
}
if (!function_exists('useBase64_encode')) {
    /**
     * 安全的base64编码
     * @param string $data 待编码的数据
     */
    function useBase64_encode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
if (!function_exists('useBase64_decode')) {
    /**
     * 安全的base64解码
     * @param string $data 待解码的数据
     */
    function useBase64_decode($data, $strict = false)
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data), $strict);
    }
}
if (!function_exists('useAES128')) {
    /**
     * AES-128-CBC 加密解密
     * 
     * @param string|array $input array=加密(数组转json加密)，string=解密(传入加密串)
     * @param string $salt 密钥盐 默认空
     * @param string $info 标记用途 默认空
     * @return array|string|false 加密返回safe-base64字符串；解密返回原数组；失败false
     */
    function useAES128($input, $salt = '', $info = '')
    {
        // 派生密钥
        $key = hash_hkdf('sha256', $salt, 16, $info);
        try {
            // 加密
            if (is_array($input)) {
                $iv = openssl_random_pseudo_bytes(16); // CBC固定16字节iv
                $cipherRaw = openssl_encrypt(json_encode($input, 320), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
                // iv(16字节) + 密文，然后url安全base64
                return $cipherRaw ? useBase64_encode($iv . $cipherRaw) : false;
            }
            // 解密
            if (is_string($input)) {
                $raw = useBase64_decode($input);
                if (strlen($raw) < 16) {
                    return false;
                }
                // 前16字节IV，后面是密文
                $iv = substr($raw, 0, 16);
                $cipherRaw = substr($raw, 16);
                $decryptStr = openssl_decrypt($cipherRaw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
                return $decryptStr ? json_decode($decryptStr, true) : false;
            }
            return false;
        } catch (\Throwable $th) {
            return false;
        }
    }
}
if (!function_exists('useAES256')) {
    /**
     * AES-256-CBC 加密解密
     * 
     * @param string|array $input array=加密(数组转json加密)，string=解密(传入加密串)
     * @param string $salt 密钥盐 默认空
     * @param string $info 标记用途 默认空
     * @return array|string|false 加密返回safe-base64字符串；解密返回原数组；失败false
     */
    function useAES256($input, $salt = '', $info = '')
    {
        // 派生密钥
        $key = hash_hkdf('sha256', $salt, 32, $info);
        try {
            // 加密
            if (is_array($input)) {
                $jsonStr = json_encode($input, 320);
                $iv = openssl_random_pseudo_bytes(16);
                $cipherRaw = openssl_encrypt($jsonStr, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                // iv(16字节) + 密文，然后url安全base64
                return $cipherRaw ? useBase64_encode($iv . $cipherRaw) : false;
            }
            // 解密
            if (is_string($input)) {
                $raw = useBase64_decode($input);
                if (strlen($raw) < 16) {
                    return false;
                }
                // 前16字节IV，后面是密文
                $iv = substr($raw, 0, 16);
                $cipherRaw = substr($raw, 16);
                $decryptStr = openssl_decrypt($cipherRaw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                return $decryptStr ? json_decode($decryptStr, true) : false;
            }
            return false;
        } catch (\Throwable $th) {
            return false;
        }
    }
}
if (!function_exists('useYouloge')) {
    /**
     * Youloge 洋葱加解密(不含签名验证)
     * @param string|array $input 待加解密的数据
     * @param string|null $info 签名密钥标记 默认`onion:hmac-v1` 如果配置为`null` 表示跳过HMAC签名校验(用于解密官方数据)
     * @param string $appid 配置参数主键 默认`youloge`
     * @return string|false 加密返回safe-base64字符串；解密返回原字符串；失败false
     */
    function useYouloge($input, $info = 'onion:hmac-v1', $appid = 'youloge')
    {
        try {
            @['apikey' => $apikey, 'secret' => $secret] = pluginConfig($appid);
            if ($apikey === '' || $secret === '') {
                return false;
            }
            $binary = useBase64_decode($secret);
            // HMAC 派生密钥
            $keyInner = hash_hkdf('sha256', $binary, 32, 'youloge:onion:inner-aes-v1');
            $keyOuter = hash_hkdf('sha256', $binary, 32, 'youloge:onion:outer-aes-v1');
            $keySiger = hash_hkdf('sha256', $binary, 32, $info);

            // 加密
            if (is_array($input)) {
                $Raw = json_encode($input, 320);
                $iv = openssl_random_pseudo_bytes(16);
                $innerBin = openssl_encrypt($Raw, 'AES-256-CBC', $keyInner, OPENSSL_RAW_DATA, $iv);
                if ($innerBin === false) return false;
                $outerBin = openssl_encrypt($innerBin, 'AES-256-CBC', $keyOuter, OPENSSL_RAW_DATA, $iv);
                if ($outerBin === false) return false;
                $sign = hash_hmac('sha256', $outerBin, $keySiger, false);
                // 打包：IV + 外层密文 + 签名
                return useBase64_encode($iv . $outerBin . $sign);
            }

            // 解密
            if (is_string($input)) {
                $rawBin = useBase64_decode($input);
                $iv = substr($rawBin, 0, 16);
                $signPacked = substr($rawBin, -64);      // 永远取出末尾64字节签名
                $cipherOuterBin = substr($rawBin, 16, -64); // 永远取出中间密文段
                // 是否跳过签名验证
                if ($info !== null && $keySiger !== null) {
                    $calcSign = hash_hmac('sha256', $cipherOuterBin, $keySiger, false);
                    if (!hash_equals($signPacked, $calcSign)) {
                        return false;
                    }
                }
                $innerBin = openssl_decrypt($cipherOuterBin, 'AES-256-CBC', $keyOuter, OPENSSL_RAW_DATA, $iv);
                if ($innerBin === false) return false;
                $jsonStr = openssl_decrypt($innerBin, 'AES-256-CBC', $keyInner, OPENSSL_RAW_DATA, $iv);
                if ($jsonStr === false) return false;
                return json_decode($jsonStr, true) ?? false;
            }
            return false;
        } catch (\Throwable $th) {
            return false;
        }
    }
}
/**
 * =============================
 * = 七牛相关
 * =============================
 */

if (!function_exists('useQiniu')) {
    /**
     * 七牛管理凭证-新版
     * @param string $method 请求方式 GET/POST/PUT/DELETE
     * @param string $uri 请求网址路径，支持 api.qiniu.com/xxx 或 https://api.qiniu.com/xxx
     * @param array $query 查询参数
     * @param array|string $body 请求内容，数组自动json_encode
     * @param string $appid 配置参数主键 默认`qiniu`
     */
    function useQiniu($method, $uri, $query = [], $body = '', $appid = 'qiniu')
    {
        @['ak' => $ak, 'sk' => $sk] = pluginConfig($appid);
        $url = str_starts_with($uri, 'https://') ? $uri : 'https://' . $uri;
        @['scheme' => $scheme, 'host' => $host, 'path' => $path, 'query' => $queryString] = parse_url($url);

        $urlQueryArr = [];
        if (!empty($queryString)) {
            parse_str($queryString, $urlQueryArr);
        }
        $finalQuery = http_build_query(array_merge($urlQueryArr, $query));
        $signUri = $finalQuery === '' ? $path : "$path?$finalQuery";

        $bodyRaw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $xQiniuDate = gmdate('Ymd\THis\Z');
        $signRaw = "{$method} {$signUri}\nHost: {$host}\nX-Qiniu-Date: {$xQiniuDate}\n\n{$bodyRaw}";

        $hmacRaw = hash_hmac('sha1', $signRaw, $sk, true);
        $sign = rtrim(strtr(base64_encode($hmacRaw), '+/', '-_'), '=');
        $authHeader = "Qiniu {$ak}:{$sign}";
        $method = strtoupper($method);
        $finalUrl = "https://{$host}{$signUri}";

        $headers = [
            'Authorization' => $authHeader,
            'Host' => $host,
            'X-Qiniu-Date' => $xQiniuDate,
            'Content-Type' => 'application/json',
        ];

        return [$finalUrl, [
            'method' => $method,
            'headers' => $headers,
            'body' => $bodyRaw
        ]];
    }
}
if (!function_exists('useQiniuQbox')) {
    /**
     * 七牛管理凭证-旧版(rs.qiniu.com / rsf.qiniu.com / fusion.qiniuapi.com)
     * @param string $method 请求方式 GET/POST/PUT/DELETE
     * @param string $uri 请求网址路径
     * @param array $query 查询参数
     * @param array $body 请求内容
     * @param string $appid 配置参数主键 默认`qiniu`
     */
    function useQiniuQbox($method, $uri, $query = [], $body = '', $appid = 'qiniu')
    {
        @['ak' => $ak, 'sk' => $sk] = pluginConfig($appid);
        $url = str_starts_with($uri, 'https://') ? $uri : 'https://' . $uri;
        @['scheme' => $scheme, 'host' => $host, 'path' => $path, 'query' => $queryString] = parse_url($url);
        // 解析url自带query
        $queryString && parse_str($queryString, $urlQueryArr);
        $finalQuery = http_build_query(array_merge($urlQueryArr ?? [], $query));
        $signUri = $finalQuery === '' ? $path : "$path?$finalQuery";
        // QBox 签名串 uri\nbody
        $bodyRaw = is_string($body) ? $body : json_encode($body, 320);
        $signRaw = $signUri . "\n" . $bodyRaw;
        $hmacRaw = hash_hmac('sha1', $signRaw, $sk, true);
        $sign = rtrim(strtr(base64_encode($hmacRaw), '+/', '-_'), '=');
        $authHeader = "QBox {$ak}:{$sign}";
        $method = strtoupper($method);
        return ["https://{$host}{$signUri}", [
            'method' => $method,
            'headers' => [
                'Authorization' => $authHeader,
                'Content-Type' => $method === 'GET' ? 'application/x-www-form-urlencoded' : 'application/json',
            ],
            'data' => $bodyRaw
        ]];
    }
}
if (!function_exists('useQiniuToken')) {
    /**
     * 七牛上传Token
     * @param array $params 待签名数组对象
     * @param string $appid 配置参数主键 默认`qiniu`
     * @return string 返回上传Token
     */
    function useQiniuToken($params, $appid = 'qiniu')
    {
        @['ak' => $ak, 'sk' => $sk] = pluginConfig($appid);
        $string = str_replace(['+', '/'], ['-', '_'], base64_encode(json_encode($params)));
        $sign = str_replace(['+', '/'], ['-', '_'], base64_encode(hash_hmac('sha1', $string, $sk, true)));
        return "$ak:$sign:$string";
    }
}
if (!function_exists('useQiniuDownload')) {
    /**
     * 七牛下载签名
     * @param string $url 待签名下载网址
     * @param int $second 可选：设置有效时间 默认3600秒
     * @param string $attname 可选：设置下载文件名 默认没有
     * @param string $appid 配置参数主键 默认`qiniu`
     * @return string 返回签名后的下载网址
     */
    function useQiniuDownload($url, $second = 3600, $attname = '', $appid = 'qiniu')
    {
        @['ak' => $AK, 'sk' => $SK] = pluginConfig($appid);
        @['scheme' => $scheme, 'host' => $host, 'path' => $path, 'query' => $queryString] = parse_url($url);
        $queryString && parse_str($queryString, $query);
        $query['e'] = time() + $second;
        $uri = sprintf("%s://%s%s", $scheme, $host, $path);
        $string = sprintf('%s?%s', $uri, http_build_query($query));
        $sign = str_replace(['+', '/'], ['-', '_'], base64_encode(hash_hmac('sha1', $string, $SK, true)));
        $query['token'] = "$AK:$sign";
        $attname && $query['attname'] = urlencode($attname);
        return $uri . '?' . http_build_query($query);
    }
}
/**
 * =============================
 * = 支付算法类 详细配置文件
 * = 证书路径 {$appid}.{apiclient_key}
 * = 证书格式 1. ./file.pem 文件路径 PEM编码的证书/私钥|公钥 2. PEM格式的私钥|公钥
 * =============================
 */
if (!function_exists('usePrivateKeySign')) {
    /***
     * 
     * 私钥签名 - 配置路径：[{appid}.apiclient_key]
     * @param string $string 待签名字符串
     * @param string $appid 选择那个id下得的证书
     * @return array
     * 成功 [err=>200,data=>base64] 
     * 失败 [err=>500,msg=>'签名错误']
     */
    function usePrivateKeySign($string, $appid)
    {
        try {
            @['apiclient_key' => $apiclient_key] = pluginConfig("$appid");
            openssl_sign($string, $raw_sign, openssl_pkey_get_private($apiclient_key), 'sha256WithRSAEncryption');
            return ['err' => 200, 'data' => base64_encode($raw_sign)];
        } catch (\Throwable $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('usePublicKeyVerify')) {
    /***
     * 
     * 公钥验签 - 配置路径：[{appid}.public_key]
     * @param string $string 待签名字符串
     * @param string $signature 待验签签名
     * @param string $appid 选择那个id下得的证书
     * @return array
     * 成功 [err=>200,'msg'=>验签通过] 
     * 失败 [err=>500, msg=>'签名错误']
     */
    function usePublicKeyVerify($string, $signature, $appid)
    {
        try {
            @['public_key' => $public_key] = pluginConfig("$appid");
            openssl_verify($string, $signature, openssl_pkey_get_public($public_key), 'sha256WithRSAEncryption');
            return ['err' => 200, 'msg' => '验签通过'];
        } catch (\Throwable $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('useTencentRequest')) {
    /**
     * 构造腾讯云请求体 - 配置路径(一律小写)：[youloge.{appid}.secretid|secretkey]
     * 签名方法：TC3-HMAC-SHA256
     * @param string $method  请求方式 GET/POST
     * @param string $endpoint_action_version_region  接入点/方法/版本/区域 
     * trtc.tencentcloudapi.com/DescribeInstances/2019-07-22/ap-guangzhou
     * @param array $payload  请求载体 无参数时 设为[],null,false,0 即可
     * @param string $appid  选择那个商户id下得的证书
     */
    function useTencentRequest($method, $endpoint_action_version_region, $payload, $appid)
    {
        @['secretid' => $SecretId, 'secretkey' => $SecretKey] = pluginConfig("youloge.$appid");
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
if (!function_exists('useWeixinPayRequest')) {
    /***
     * 构造微信支付请求体 - 配置路径：[{appid}.apiclient_key|serial_no...]
     * useWeixinPayRequest('GET','/v3/certificates',{},11111111);
     * 
     * @param string $method 请求网络方式 GET/POST
     * @param string $router 请求网络路径 必须'/'开头
     * @param array $data JSON数据 不传设置为 '' false 0 即可
     * @param string $appid 选择那个商户id下得的证书
     */
    function useWeixinPayRequest($method, $router, $data = '', $appid = '')
    {
        @['apiclient_key' => $apiclient_key, 'serial_no' => $serial_no] = pluginConfig("$appid");
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
if (!function_exists('useWeixinPayVerify')) {
    /**
     * 微信回调验签 - 配置路径：[{serial}.platform_cert]
     * @param object $request Request 给返回对象传进来
     * 成功返回 对象返回JSON 否则返回 []
     * 失败返回 ['err'=>500,'msg'=>Exception]
     */
    function useWeixinPayVerify($request)
    {
        try {
            @['Wechatpay-Timestamp' => $Timestamp, 'Wechatpay-Nonce' => $Nonce, 'Wechatpay-Signature' => $Signature, 'Wechatpay-Serial' => $Serial] = $request->header();
            @['platform_cert' => $platform_cert] = pluginConfig("$Serial");
            $rawBody = $request->getContent();
            $verify = (bool) openssl_verify("$Timestamp\n$Nonce\n$rawBody\n", base64_decode($Signature), openssl_get_publickey($platform_cert), 'sha256WithRSAEncryption');
            return $verify ? $request->all() : [];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('useWeixinPayDecryptV3')) {
    /**
     * 微信解密V3 - 配置路径：[youloge.{mchid}.v3key]
     * @param array $encrypt 解密数据 要有['ciphertext','nonce','associated_data'] 
     * @param string $mchid 选择那个商户id下得的证书
     * 成功返回 对象返回JSON 否则返回 ['raw'=>$raw]
     * 失败返回 ['err'=>500,'msg'=>Exception]
     */
    function useWeixinPayDecryptV3($encrypt, $mchid)
    {
        try {
            @['v3key' => $v3key] = pluginConfig("$mchid");
            @['ciphertext' => $ciphertext, 'nonce' => $nonce, 'associated_data' => $associated] = $encrypt;
            $cipher = base64_decode($ciphertext);
            $decrypt = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', $v3key, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16), $associated);
            return json_decode($decrypt, true) ?? ['raw' => $decrypt];
        } catch (\Exception $e) {
            return ['err' => 500, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('useAliPayRequest')) {
    /**
     * 构造支付宝支付请求体 - 配置路径：[appid.{appid}.apiclient_key]
     * useAliPayRequest('alipay.trade.create',$data,11111111);
     * @param string $method  接口名称 alipay.trade.create ...
     * @param array $params  请求参数
     * @param string $appid  选择那个商户id下得的证书
     */
    function useAliPayRequest($method, $params, $appid)
    {
        @['apiclient_key' => $apiclient_key] = pluginConfig("$appid");
        $public = [
            'app_id' => $appid,
            'method' => $method,
            'version' => '1.0',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $body = array_merge($public, $params);
        ksort($body);
        openssl_sign(urldecode(http_build_query($body)), $raw_sign, openssl_pkey_get_private($apiclient_key), 'sha256WithRSAEncryption');
        $body['sign'] = base64_encode($raw_sign);
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
if (!function_exists('useAliPayVerify')) {
    /**
     * 支付宝验签 - 配置路径：[youloge.alipay.public_key]
     * @param object $request Request 给返回对象传进来
     * @param string $appid  选择那个商户id下得的证书
     * 成功返回 对象返回JSON 否则返回 []
     * 失败返回 ['err'=>500,'msg'=>Exception]
     */
    function useAliPayVerify($request, $appid)
    {
        try {
            @['public_key' => $alipay_public_key] = pluginConfig($appid);
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
 * 扩展方法 
 * 用于兼容 (PHP 8 <= 8.1.0) 7.2+
 * 判断是否可循环数组
 */
if (!function_exists('ini')) {
    /**
     * 读取配置文件参数
     * `ini(null)`返回全部配置 
     * `ini('MYSQL','默认值')` 返回一级配置[数组]
     * `ini('MYSQL.HOST')` 返回三级配置[字符串]
     * @param string $keys 配置路径
     * @param string $def 默认值
     * @return string|array 返回值
     */
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
if (!function_exists('useValidate')) {
    /**
     * 验证和处理表单数据
     *
     * 可以通过多个调用方式 实现复杂处理
     *
     * @param array $params 表单数据
     * @param array $rules 验证规则
     * @param bool $intersect 是否只返回验证通过的数据
     * @return array $result 验证结果
     * @throws array 验证失败抛出异常 ['err'=>400,'msg'=>'错误提示']
     * @example
     */
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
                return (int)(($param === null || $param === '') ? $args : $param);
            },
            'bool' => function ($field, $param, $args, $msg = '') {
                return (bool)(($param === null || $param === '') ? $args : $param);
            },
            'float' => function ($field, $param, $args, $msg = '') {
                return (float)(($param === null || $param === '') ? $args : $param);
            },
            'string' => function ($field, $param, $args, $msg = '') {
                return (string)(($param === null || $param === '') ? $args : $param);
            },
            'array' => function ($field, $param, $args, $msg = '') {
                return (array)(($param === null || $param === '' || empty($param)) ? json_decode("[$args]", true) : $param);
            },
            'object' => function ($field, $param, $args, $msg = '') {
                return (object)(($param === null || $param === '' || empty($param)) ? json_decode("{{$args}}", false) : $param);
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
                @[$field => $param] = $params;
                // 1. 处理可迭代规则（数组类型规则）
                if (is_iterable($rule)) {
                    // 1.1 索引数组：流水线/数组元素遍历
                    if (array_is_list($rule)) {
                        if (count($rule) == 1) {
                            if (!is_array($param)) {
                                throw new Exception("{$field}：必须是一维数组");
                            }
                            // 值遍历
                            foreach ($params[$field] as $index => $paraming) {
                                @['err' => $err, 'msg' => $msg, $index => $callback] = $back = useValidate([$index => $paraming], [$index => $rule[0]], $intersect);
                                if ($err === 400) {
                                    throw new Exception("$msg");
                                }
                                $params[$field][$index] = $callback;
                            }
                            // 流水线规则遍历
                        } else {
                            foreach ($rule as $_rule) {
                                @[$field => $current] = $params;
                                @['err' => $err, 'msg' => $msg, $field => $callback] = $back = useValidate([$field => $current], [$field => $_rule], $intersect);
                                if ($err === 400) {
                                    throw new Exception("$field.$msg");
                                }
                                $params[$field] = $callback;
                            }
                        }

                        // 1.2 关联数组：单个对象规则（子字段数据流转）
                    } else {
                        @['err' => $err, 'msg' => $msg] = $callback = useValidate($param, $rule, $intersect);
                        if ($err === 400) {
                            throw new Exception("$field.$msg");
                        }
                        $params[$field] = $callback;
                    }
                    continue;
                }
                // 闭包回调规则
                if (is_callable($rule)) {
                    try {
                        // 闭包返回的预处理结果，作为后续规则的输入
                        $params[$field] = $rule($field, $param);
                    } catch (Exception $e) {
                        throw new Exception($e->getMessage(), 400);
                    }
                    continue;
                }
                // 2. 处理字符串规则（单规则/多规则组合） expression
                if (is_string($rule)) {
                    @[$expression, $customMsg] = explode('#', $rule);
                    $pipeline = explode('|', $expression);
                    $required = str_contains($expression, 'required') || str_contains($expression, 'require');
                    foreach ($pipeline as $step) {
                        @[$ruleName, $ruleParam] = explode(':', $step, 2);
                        @[$ruleName => $method] = $presets;
                        // 非必填且值为null，或规则不存在时跳过（支持默认值）
                        if (($param === null && $required === false) || $method === null) {
                            // 类型转换规则的默认值处理
                            if (in_array($ruleName, ['int', 'bool', 'float', 'string', 'array', 'object']) && !is_null($ruleParam)) {
                                $param = $method($field, $param, $ruleParam); // 更新param值，确保后续规则能使用
                                $params[$field] = $param;
                            }
                            continue;
                        }
                        // 执行验证规则
                        $args = [$field, $param, $ruleParam];
                        if ($customMsg) {
                            array_push($args, $customMsg);
                        }
                        $param = $method(...$args);
                        $params[$field] = $param;
                        // 更新param为最新值，支持规则流转（前一个规则结果作为后一个输入）
                    }
                }
            }
            return $intersect ? array_intersect_key($params, $rules) : $params;
        } catch (Exception $e) {
            return ['err' => 400, 'msg' => $e->getMessage()];
        }
    }
}
if (!function_exists('pluginConfig')) {
    /**
     * Get config
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function pluginConfig(?string $key = null, mixed $default = null)
    {
        return config("plugin.youloge.tools.app.$key", $default);
    }
}
if (!function_exists('array_is_list')) {
    function array_is_list($arg)
    {
        return $arg === [] || (array_keys($arg) === range(0, count($arg) - 1));
    }
}
