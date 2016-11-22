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
namespace tests\model;

use think\Model;

/**
 * Class User
 */
class User extends Model
{
    /**
     * @return static
     */
    protected function getHaaAttr()
    {
        return $this;
    }

    public function children()
    {
        return $this->belongsTo('User');
    }

    public function children2()
    {
        return $this->hasMany(self::class)->where('id',1);
    }
}