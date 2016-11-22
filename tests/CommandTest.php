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
namespace tests;

use think\console\Input;
use think\console\Output;
use yunwuxin\model\helper\Command;

class CommandTest extends \PHPUnit_Framework_TestCase
{
    public function testCommand()
    {
        $input = new Input(['tests\\model\\User','-O']);

        $output = new Output();

        $command = new Command();

        $command->run($input, $output);

    }
}