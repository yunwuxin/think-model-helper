<?php
/**
 * Created by PhpStorm.
 * User: yunwuxin
 * Date: 2019/3/12
 * Time: 18:56
 */

namespace yunwuxin\model\helper;


class Service extends \think\Service
{
    public function boot()
    {
        $this->commands([
            Command::class
        ]);
    }
}