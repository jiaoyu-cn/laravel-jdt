# laravel-jdt

基于laravel的校对通智能校对

[![image](https://img.shields.io/github/stars/jiaoyu-cn/laravel-jdt)](https://github.com/jiaoyu-cn/laravel-jdt/stargazers)
[![image](https://img.shields.io/github/forks/jiaoyu-cn/laravel-jdt)](https://github.com/jiaoyu-cn/laravel-jdt/network/members)
[![image](https://img.shields.io/github/issues/jiaoyu-cn/laravel-jdt)](https://github.com/jiaoyu-cn/laravel-jdt/issues)

## 安装

```shell
composer require githen/laravel-jdt:~v1.0.0

# 迁移配置文件
php artisan vendor:publish --provider="Githen\LaravelJdt\Providers\JdtServiceProvider"
```

## 配置文件说明

生成`jdt.php`上传配置文件

```php
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | 校对通配置
    |--------------------------------------------------------------------------
    |
    */
    // 登录信息
    'app_id' => 'admin',
    'app_secret' => '111111',
    'disk' => 'local',
    'auth_file' => 'app/data/jdt/jdt.txt',
    'son_user' => [
        10 => [
            'son_user_id' => 'admin',
        ],
    ]
    
];
```
