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
     * @return array
     */
    public function getAccessToken($refresh = false)
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
        $accessToken = '';
        $disk = $config['disk'];
        $authFile = $config['auth_file'];
        $timeUnix = time();
        if (Storage::disk($disk)->has($authFile)) {
            $obj = json_decode(Storage::disk($disk)->get($authFile), true);
            $accessToken = $obj['access_token'] ?? '';
            $expireTime = $obj['expire_time'] ?? 0;
            if (!$refresh && $expireTime - $timeUnix > 300) {
                return $this->message('0000', "登录成功", ['access_token' => $accessToken, 'expire_time' => $expireTime - $timeUnix]);
            }
        }
        if (!empty($config['custom_auth_func']) && function_exists($config['custom_auth_func'])) {
            $loginToken = $config['custom_auth_func']($config['custom_auth_url'], [
                'access_token' => $accessToken,
            ]);
        } else {
            $loginAuthorize = $this->loginAuthorize($config['app_id']);
            if ($loginAuthorize['code'] != '0000') {
                return $this->message($loginAuthorize['code'], $loginAuthorize['message']);
            }
            $loginToken = $this->loginToken($config['app_id'], $config['app_secret'], $loginAuthorize['data']['authorization_code']);
        }

        if ($loginToken['code'] == '0000') {
            $obj = [
                'access_token' => $loginToken['data']['access_token'],
                'expire_time' => $timeUnix + $loginToken['data']['expire_time']
            ];
            if (Storage::disk($disk)->has($authFile)) {
                Storage::disk($disk)->put($authFile, json_encode($obj));
            } else {
                Storage::disk($disk)->put($authFile, json_encode($obj), 'public');
            }
            return $this->message('0000', "登录成功", [
                'access_token' => $obj['access_token'],
                'expire_time' => $loginToken['data']['expire_time']
            ]);
        }
        return $this->message($loginToken['code'], $loginToken['message']);
    }

    /** 获取授权码
     * @param $appId string 应用ID
     * @param $state string 状态
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
     * @param $appId string 应用ID
     * @param $appSecret string 应用密钥
     * @param $authorizeCode string 授权码
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
                'expire_time' => intval(($resp['accessToken']['expireIn'] ?? 0) / 1000),
            ]
        );
    }

    /** 文本检测错误类型
     * @return array|mixed
     */
    public function wbjcGetCorrectAbility()
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_correct_ability";
        $resp = $this->httpPost($uri);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 文本校对接口
     * @param $text string 文本内容
     * @param $abilityIds string 能力id
     * @param $sonUserId string 子账号Id
     * @return array|mixed
     */
    public function wbjcArticleCorrectExternal($text, $abilityIds, $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/article_correct_external";
        if ($abilityIds == 'all') {
            $abilityIds = '9,31,32,35,34,39,36,20,21,24,23,45,44,48,101,19,124,8,122,240,6,46,42,105,109,112,111,108,118,38,119,241,47,49,3001';
        }
        $params = [
            'text' => $text,
            'ability_ids' => $abilityIds,
        ];
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params, ['tranctionId' => md5(config('app_id') . time() . Str::random(4))]);
        if ($resp['code'] == 200) {
            $code = '0000'; //检测完毕
        } else {
            $code = '2000'; //请求报错
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 获取文本校对用户信息状态接口
     * @return array|mixed
     */
    public function wbjcGetWbjcAuthInfo()
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_wbjc_auth_info";
        $resp = $this->httpPost($uri);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 查询子账号
     * @param $page int 页码
     * @param $pageCount int 每页数量
     * @param $searchValue string 搜索值
     * @return array|mixed
     */
    public function wbjcGetSonUser($page = 1, $pageCount = 10, $searchValue = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_son_user";
        $params = [
            'page' => $page,
            'pageCount' => $pageCount,
        ];
        if (!empty($searchValue)) {
            $params['searchValue'] = $searchValue;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 添加子账号
     * @param $sonUserName string 子账号名称
     * @return array|mixed
     */
    public function wbjcAddSonUser($sonUserName)
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/add_son_user";
        $resp = $this->httpPost($uri, [
            'son_user_name' => $sonUserName,
        ]);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 编辑子账号
     * @param $updateSonUserId string 子账号ID
     * @param $sonUserName string 子账号名称
     * @return array|mixed
     */
    public function wbjcUpdateSonUser($updateSonUserId, $sonUserName)
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/update_son_user";
        $resp = $this->httpPost($uri, [
            'update_son_user_id' => $updateSonUserId,
            'son_user_name' => $sonUserName,
        ]);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 删除子账号
     * @param $delSonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcDelSonUser($delSonUserId)
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/del_son_user";
        $resp = $this->httpPost($uri, [
            'del_son_user_id' => $delSonUserId,
        ]);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 获取自定义词列表
     * @param $wordType string 自定义词类型,1禁用词2敏感词3正词4错词5重点词
     * @param $page int 页码
     * @param $pageCount int 每页数量
     * @param $searchWord string 搜索词
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcGetWordList($wordType, $page = 1, $pageCount = 10, $searchWord = '', $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_word_list";
        if (!in_array($wordType, [1, 2, 3, 4, 5])) {
            return $this->message('2000', '自定义词类型错误');
        }
        $params = [
            'word_type' => $wordType,
            'page' => $page,
            'totalPage' => $pageCount,
        ];
        if (!empty($searchWord)) {
            $params['search_word'] = $searchWord;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 添加自定义词
     * @param $wordType string 自定义词类型,1禁用词(word_reason:禁用的原因)2敏感词(word_reason:敏感类型)3正词4错词(word_reason:正词)5重点词
     * @param $wordText string 词名称
     * @param $wordReason string 词原因
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcAddWord($wordType, $wordText, $wordReason = '', $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/add_word";
        if (!in_array($wordType, [1, 2, 3, 4, 5])) {
            return $this->message('2000', '自定义词类型错误');
        }
        $params = [
            'word_type' => $wordType,
            'word_text' => $wordText,
        ];
        if (!empty($wordReason)) {
            $params['word_reason'] = $wordReason;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 编辑自定义词
     * @param $detectionLexiconId string 要操作的自定义词ID
     * @param $wordText string 词名称
     * @param $wordReason string 词原因
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcEditWord($detectionLexiconId, $wordText, $wordReason = '', $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/edit_word";
        $params = [
            'detection_lexicon_id' => $detectionLexiconId,
            'word_text' => $wordText,
        ];
        if (!empty($wordReason)) {
            $params['word_reason'] = $wordReason;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 批量添加自定义词
     * @param $words array 自定义词库 格式：[['word_text' => 'a', 'word_reason' => 'b', 'word_type' => 1]]
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcBatchAddWord($words = [], $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/batch_add_word";
        if (empty($words)) {
            return $this->message('2000', '自定义词不允许为空');
        }
        $params['json_str'] = json_encode($words, JSON_UNESCAPED_UNICODE);
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 通用-词操作状态
     * @param $detectionLexiconIds string|array 词库ID，多个逗号分隔，支持数组
     * @param $status string 状态1启用2停用
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcHandleWordStatus($detectionLexiconIds, $status, $sonUserId = '')
    {
        $this->wbjcHandleWord(2, $detectionLexiconIds, $status, $sonUserId);
    }

    /** 通用-词操作删除
     * @param $detectionLexiconIds string|array 词库ID，多个逗号分隔，支持数组
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcDelWord($detectionLexiconIds, $sonUserId = '')
    {
        $this->wbjcHandleWord(3, $detectionLexiconIds, 1, $sonUserId);
    }

    /** 通用-词操作
     * @param $handleType string 操作类型 2 启用停用 3 删除
     * @param $detectionLexiconIds string|array 词库ID，多个逗号分隔，支持数组
     * @param $status string 状态1启用2停用
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcHandleWord($handleType, $detectionLexiconIds, $status = 1, $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/handle_word";
        $params = [
            'handle_type' => $handleType,
            'detection_lexicon_ids' => is_array($detectionLexiconIds) ? implode(',', $detectionLexiconIds) : $detectionLexiconIds,
        ];
        if ($handleType == 2) {
            $params['status'] = $status;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /**  通用-清空词
     * @param $wordType string 自定义词类型,1禁用词2敏感词3正词4错词5重点词6自定义领导人职务7自定义领导人排序
     * @param $sonUserId string 子账号ID
     * @return array|mixed
     */
    public function wbjcDelAllWord($wordType, $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/del_all_word";
        if (!in_array($wordType, [1, 2, 3, 4, 5, 6, 7])) {
            return $this->message('2000', '自定义词类型错误');
        }
        $params = [
            'word_type' => $wordType,
        ];
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 添加机构
     * @param $administrativeType string 行政级别1国家2省份3地市级4区/县5乡/镇6村
     * @param $name  string 名称
     * @param $level  string 等级1一级2二级3三级
     * @param $pid  string 父级ID，非第一级机构的时候需要传
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcAddMechanism($administrativeType, $name, $level = 1, $pid = 0, $sonUserId = '')
    {
        return $this->wbjcAddMechanismOrPost($administrativeType, 0, $name, $level, $pid, $sonUserId);
    }

    /** 添加职务
     * @param $administrativeType string 行政级别1国家2省份3地市级4区/县5乡/镇6村
     * @param $name  string 名称
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcAddPost($administrativeType, $name, $sonUserId = '')
    {
        return $this->wbjcAddMechanismOrPost($administrativeType, 1, $name, 1, 0, $sonUserId);
    }

    /** 添加机构或者职务
     * @param $administrativeType string 行政级别1国家2省份3地市级4区/县5乡/镇6村
     * @param $type string 类型0机构1职务
     * @param $name  string 名称
     * @param $level  string 等级1一级2二级3三级
     * @param $pid  string 父级ID，非第一级机构的时候需要传
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcAddMechanismOrPost($administrativeType, $type, $name, $level = 0, $pid = 0, $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/add_mechanism_or_post";
        if (!in_array($administrativeType, [1, 2, 3, 4, 5, 6])) {
            return $this->message('2000', '行政级别错误');
        }
        $params = [
            'administrative_type' => $administrativeType,
            'type' => $type,
            'name' => $name,
            'p_id' => $pid,
        ];
        if ($type == 0) {
            $params['level'] = $level;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 获取领导人所有添加需要的数据
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcGetLeaderSelectData($sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_leader_select_data";
        $params = [];
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 获取领导人列表
     * @param $page  int 页码
     * @param $pageCount  int 每页数量
     * @param $searchWord  string 搜索词
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcGetLeaderPeople($page = 1, $pageCount = 10, $searchWord = '', $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/get_leader_people";
        $params = [
            'page' => $page,
            'totalPage' => $pageCount,
        ];
        if (!empty($searchWord)) {
            $params['search_word'] = $searchWord;
        }
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 添加领导人
     * @param $administrativeType  string 行政级别1国家2省份3地市级4区/县5乡/镇6村
     * @param $organIds  string 机构ID，多个用逗号间隔，支持数组
     * @param $dutyId  string 职务ID
     * @param $leaderName  string 领导人名称
     * @param $otherParams  array 其他参数，省市区县
     * @param $sonUserId  子账号ID
     * @return array|mixed
     */
    public function wbjcAddLeaderPeople($administrativeType, $organIds, $dutyId, $leaderName, $otherParams = [], $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/add_leader_people";
        $params = [
            'administrative_type' => $administrativeType,
            'organ_ids' => is_array($organIds) ? implode(',', $organIds) : $organIds,
            'duty_id' => $dutyId,
            'leader_name' => $leaderName,
        ];
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $params = array_merge($params, $otherParams);
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /** 编辑领导人
     * @param $id  string 领导人ID
     * @param $administrativeType  string 行政级别1国家2省份3地市级4区/县5乡/镇6村
     * @param $organIds  string 机构ID，多个用逗号间隔，支持数组
     * @param $dutyId  string 职务ID
     * @param $leaderName  string 领导人名称
     * @param $otherParams  array 其他参数，省市区县
     * @param $sonUserId  string 子账号ID
     * @return array|mixed
     */
    public function wbjcSaveLeaderPeople($id, $administrativeType, $organIds, $dutyId, $leaderName, $otherParams = [], $sonUserId = '')
    {
        $uri = "/dataapp/api/umei/fw/open/wbjc/save_leader_people";
        $params = [
            'id' => $id,
            'administrative_type' => $administrativeType,
            'organ_ids' => is_array($organIds) ? implode(',', $organIds) : $organIds,
            'duty_id' => $dutyId,
            'leader_name' => $leaderName,
        ];
        if (!empty($sonUserId)) {
            $params['son_user_id'] = $sonUserId;
        }
        $params = array_merge($params, $otherParams);
        $resp = $this->httpPost($uri, $params);
        if ($resp['code'] == 200) {
            $code = '0000'; //获取成功
        } else {
            $code = '2000'; //获取失败
        }
        return $this->message($code, $resp['message'] ?? $resp['msg'], $resp['data'] ?? []);
    }

    /**
     * @param $uri string 接口地址
     * @param $params array 请求参数
     * @param $headers array 请求头
     * @param $isLogin bool 是否登录
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
            'connect_timeout' => 10,
            'verify' => false,
            'handler' => $handlerStack,
        ]);
        try {
            $uri = $baseURL . $uri;
            $response = $httpClient->request('POST', $uri, $options);
            $content = json_decode($response->getBody()->getContents(), true);
            if ($isLogin && ($content['code'] == 30004 || $content['code'] == 400)) {
                // 30004 token过期重试
                $authorization = $this->getAccessToken(true);
                if ($authorization['code'] != '0000') {
                    return $this->message($authorization['code'], $authorization['message']);
                }
                $options['form_params']['accessToken'] = $authorization['data']['access_token'];
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
