<?php
/**
 * Created by PhpStorm.
 * User: yunwuxin
 * Date: 2019/3/12
 * Time: 18:56
 */

namespace yunwuxin\model\helper;


use think\App;
use think\Console;

class Service
{
    public function register(App $app)
    {
        /** @var Console $console */
        $console = $app->make(Console::class);

        $console->addCommands([
            Command::class
        ]);
    }
}