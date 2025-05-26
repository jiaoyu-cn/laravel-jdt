<?php

namespace Githen\LaravelJdt\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Client as GuzzleHttpClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 自动注册为服务
 */
class JdtServiceProvider extends LaravelServiceProvider
{
    /**
     * 启动服务
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([__DIR__ . '/../config/config.php' => config_path('jdt.php')]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('jiaoyu.jdt', function ($app) {
            return $this;
        });
    }

    /** 获取AccessToken
     * @param $refresh
     * @return string
     */
    private function getAccessToken($refresh = false)
    {
        if (!$config = config('jdt')) {
            return $this->message('2000', "配置文件不存在");
        }
        // 配置校验
        foreach (['app_id', 'app_secret', 'disk', 'auth_file'] as $key) {
            if (empty($config[$key])) {
                return $this->message('2000', "配置信息【" . $key . "】不能为空");
            }
        }
        $disk = $config['disk'];
        $authFile = $config['auth_file'];
        if (!$refresh && Storage::disk($disk)->has($authFile)) {
            $obj = Storage::disk($disk)->get($authFile);
            if (!empty($obj)) {
                $obj = json_decode($obj, true);
                if (!empty($obj['expire_time']) && $obj['expire_time'] - time() > 300) {
                    return $this->message('0000', "登录成功", ['access_token' => $obj['access_token']]);
                }
            }
        }
        $loginAuthorize = $this->loginAuthorize($config['app_id']);
        if ($loginAuthorize['code'] != '0000') {
            return $this->message($loginAuthorize['code'], $loginAuthorize['message']);
        }
        $loginToken = $this->loginToken($config['app_id'], $config['app_secret'], $loginAuthorize['data']['authorization_code']);
        if ($loginToken['code'] == '0000') {
            $obj = [
                'access_token' => $loginToken['data']['access_token'],
                'expire_time' => time() + intval($loginToken['data']['expire_time'] / 1000)
            ];
            if (Storage::disk($disk)->has($authFile)) {
                Storage::disk($disk)->put($authFile, json_encode($obj));
            } else {
                Storage::disk($disk)->put($authFile, json_encode($obj), 'public');
            }
            return $this->message('0000', "登录成功", ['access_token' => $obj['access_token']]);
        }
        return $this->message($loginToken['code'], $loginToken['message']);
    }

    /** 获取授权码
     * @param $appId
     * @param $state
     * @return array|mixed
     */
    public function loginAuthorize($appId, $state = '')
    {
        $uri = "/no/authentication/login/authorize";

        $resp = $this->httpPost($uri, [
            'appId' => $appId,
            'responseType' => 'code',
            'state' => $state
        ], [], false);

        return $this->message(
            $resp['code'] != '0000' ? '2000' : '0000',
            $resp['message'],
            [
                'authorization_code' => $resp['authorizeCode']['authorizeCode'] ?? '',
                'stat' => $resp['authorizeCode']['stat'] ?? '',
            ]
        );
    }

    /** 获取 access_token
     * @param $name
     * @param $password
     * @return array|mixed
     */
    public function loginToken($appId, $appSecret, $authorizeCode)
    {
        $uri = "/no/authentication/login/token";

        $resp = $this->httpPost($uri, [
            'appId' => $appId,
            'appSecret' => $appSecret,
            'grantType' => 'authorization_code',
            'authorizeCode' => $authorizeCode
        ], [], false);

        return $this->message(
            $resp['code'] != '0000' ? '2000' : '0000',
            $resp['message'],
            [
                'access_token' => $resp['accessToken']['accessToken'] ?? '',
                'expire_time' => $resp['accessToken']['expireIn'] ?? '',
            ]
        );
    }

    /** 文本校对接口
     * @param $method
     * @param $text
     * @return array|mixed
     */
    public function wbjcArticleCorrectExternal($text, $abilityIds, $subKey = 0)
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/article_correct_external";
        if ($abilityIds == 'all') {
            $abilityIds = '9,31,32,35,34,39,36,20,21,24,23,45,44,48,101,19,124,8,122,240,6,46,42,105,109,112,111,108,118,38,119,241,47,49,3001';
        }
        $params = [
            'text' => $text,
            'ability_ids' => $abilityIds,
        ];
        if (!empty($subKey)) {
            $sonUserId = config('jt.son_user.' . $subKey . 'son_user_id');
            if (!empty($sonUserId)) {
                $params['son_user_id'] = $sonUserId;
            }
        }
        $resp = $this->httpPost($uri, $params, ['tranctionId' => md5(config('app_id') . time() . Str::random(4))]);
        if ($resp['code'] == 200) {
            $code = '0000'; //检测完毕
        } else {
            $code = '2000'; //请求报错
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /**
     * @param $uri
     * @param $params
     * @return array|mixed
     */
    public function httpPost($uri, $params = [], $headers = [], $isLogin = true)
    {
        $baseURL = 'https://cloud-gateway.midu.com';
        $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
        $options = [
            'headers' => $headers,
            'form_params' => $params,
        ];
        if ($isLogin) {
            $authorization = $this->getAccessToken(false);
            if ($authorization['code'] != '0000') {
                return $this->message($authorization['code'], $authorization['message']);
            }
            $options['form_params']['accessToken'] = $authorization['data']['access_token'];
        }
        $handlerStack = HandlerStack::create(new CurlHandler());
        $handlerStack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));
        $httpClient = new GuzzleHttpClient([
            'timeout' => 60,
            'verify' => false,
            'handler' => $handlerStack,
        ]);
        try {
            $uri = $baseURL . $uri;
            $response = $httpClient->request('POST', $uri, $options);
            $content = json_decode($response->getBody()->getContents(), true);
            if ($isLogin && $content['code'] == '30004') {
                // 30004 token过期重试
                $authorization = $this->getAccessToken(true);
                if ($authorization['code'] != '0000') {
                    return $this->message($authorization['code'], $authorization['message']);
                }
                $options['headers']['accessToken'] = $authorization['data']['access_token'];
                $response = $httpClient->request(
                    'POST',
                    $uri,
                    $options
                );
                $content = json_decode($response->getBody()->getContents(), true);
            }
            return $content;
        } catch (\Exception $e) {
            return $this->message($e->getCode(), $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * 最大重试次数
     */
    const MAX_RETRIES = 3;

    /**
     * 返回一个匿名函数, 匿名函数若返回false 表示不重试，反之则表示继续重试
     * @return \Closure
     */
    private function retryDecider()
    {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ) {
            // 超过最大重试次数，不再重试
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            // 请求失败，继续重试
            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response) {
                // 如果请求有响应，但是状态码不等于200，继续重试
                if ($response->getStatusCode() != 200) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * 返回一个匿名函数，该匿名函数返回下次重试的时间（毫秒）
     * @return \Closure
     */
    private function retryDelay()
    {
        return function ($numberOfRetries) {
            return 1000 * $numberOfRetries;
        };
    }

    /**
     * 封装消息
     * @param string $code
     * @param string $message
     * @param array $data
     * @return array
     */
    private function message($code, $message, $data = [])
    {
        return ['code' => $code, 'message' => $message, 'data' => $data];
    }
}
