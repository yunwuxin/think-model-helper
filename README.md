## ThinkPHP5 自动生成模型的注释

### 安装

~~~
composer require yunwuxin/think-model-helper
~~~

### 使用方法

~~~
//所有模型
php think model:annotation

//指定模型
php think model:annotation app\\User app\\Post
~~~

#### 可选参数
~~~
--dir="models" [-D] 指定自动搜索模型的目录,相对于APP_PATH的路径，可指定多个，默认为application/model

--ignore="app\\User,app\\Post" [-I] 忽略的模型，可指定多个

--overwrite [-O] 强制覆盖已有的属性注释
~~~

### 配置
配置文件位于`extra/model-helper.php`

~~~
locations 默认搜索的模型目录，数组，相对于APP_PATH的路径
~~~