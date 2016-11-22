<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

// 加载框架基础文件
require __DIR__ . '/../vendor/topthink/framework/base.php';
\think\Loader::addNamespace('tests', __DIR__ . '/');
\think\Loader::addNamespace('yunwuxin\\model\\helper', __DIR__ . '/../src/');