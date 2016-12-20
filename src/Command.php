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
namespace yunwuxin\model\helper;

use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Table;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use phpDocumentor\Reflection\DocBlock\StandardTagFactory;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\FqsenResolver;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use phpDocumentor\Reflection\Types\This;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use think\Config;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\helper\Str;
use think\Loader;
use think\Model;
use think\model\Relation;

class Command extends \think\console\Command
{

    protected $dirs = [];

    protected $properties = [];

    protected $overwrite = false;

    protected $reset = false;

    protected function configure()
    {
        $this
            ->setName('model:annotation')
            ->addArgument('model', Argument::OPTIONAL | Argument::IS_ARRAY, 'Which models to include', [])
            ->addOption('dir', 'D', Option::VALUE_OPTIONAL | Option::VALUE_IS_ARRAY, 'The model dir', [])
            ->addOption('ignore', 'I', Option::VALUE_OPTIONAL, 'Which models to ignore', '')
            ->addOption('reset', 'R', Option::VALUE_NONE, 'Remove the original phpdocs instead of appending')
            ->addOption('overwrite', 'O', Option::VALUE_NONE, 'Overwrite the phpdocs');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->dirs = array_merge(
            (array) Config::get('model-helper.locations'),
            $input->getOption('dir')
        );

        $model  = $input->getArgument('model');
        $ignore = $input->getOption('ignore');

        $this->overwrite = $input->getOption('overwrite');

        $this->reset = $input->getOption('reset');

        $this->generateDocs($model, $ignore);

    }

    /**
     * 生成注释
     * @param        $loadModels
     * @param string $ignore
     */
    protected function generateDocs($loadModels, $ignore = "")
    {
        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = [];
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $ignore = explode(',', $ignore);

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= Output::VERBOSITY_VERBOSE) {
                    $this->output->comment("Ignoring model '$name'");
                }
                continue;
            }

            $this->properties = [];

            if (class_exists($name)) {
                try {
                    $reflectionClass = new \ReflectionClass($name);
                    if (!$reflectionClass->isSubclassOf('think\Model')) {
                        continue;
                    }
                    if ($this->output->getVerbosity() >= Output::VERBOSITY_VERBOSE) {
                        $this->output->comment("Loading model '$name'");
                    }
                    if (!$reflectionClass->isInstantiable()) {
                        // 忽略接口和抽象类
                        continue;
                    }
                    $model = new $name;
                    if ($this->supportedDatabase()) {
                        $this->getPropertiesFromTable($model);
                    }
                    $this->getPropertiesFromMethods($name, $model);
                    $this->createPhpDocs($name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $this->output->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }

        }
    }

    protected function supportedDatabase()
    {
        return class_exists(AdapterFactory::class);
    }

    /**
     * 获取数据表
     * @param Model $model
     * @return Table
     */
    protected function getTable(Model $model)
    {
        $tableName = $model->db()->getTable();

        $config = $model->db()->getConnection()->getConfig();

        $options = [
            'adapter'      => $config['type'],
            'host'         => $config['hostname'],
            'name'         => $config['database'],
            'user'         => $config['username'],
            'pass'         => $config['password'],
            'port'         => $config['hostport'],
            'charset'      => $config['charset'],
            'table_prefix' => $config['prefix']
        ];

        $adapter = AdapterFactory::instance()->getAdapter($options['adapter'], $options);

        return new Table($tableName, [], $adapter);

    }

    /**
     * 从数据库读取字段信息
     * @param Model $model
     */
    protected function getPropertiesFromTable(Model $model)
    {
        $table = $this->getTable($model);

        $columns = $table->getColumns();

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                $type = $column->getType();
                switch ($type) {
                    case 'string':
                    case 'char':
                    case 'text':
                    case 'timestamp':
                    case 'date':
                    case 'time':
                    case 'guid':
                    case 'datetimetz':
                    case 'datetime':
                        $type = 'string';
                        break;
                    case 'integer':
                    case 'biginteger':
                    case 'smallint':
                        $type = 'integer';
                        break;
                    case 'decimal':
                    case 'float':
                        $type = 'float';
                        break;
                    case 'boolean':
                        $type = 'boolean';
                        break;
                    default:
                        $type = 'mixed';
                        break;
                }

                $comment = $column->getComment();
                $this->setProperty($name, $type, true, true, $comment);

            }
        }
    }

    /**
     * 自动生成获取器和修改器以及关联对象的属性信息
     * @param $class
     * @param $model
     */
    protected function getPropertiesFromMethods($class, $model)
    {
        $methods = (new \ReflectionClass($class))->getMethods();

        foreach ($methods as $method) {

            if ($method->getDeclaringClass()->getName() == $class) {

                $methodName = $method->getName();
                if (Str::startsWith($methodName, 'get') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && $methodName !== 'getAttr'
                ) {
                    //获取器
                    $name = Loader::parseName(substr($methodName, 3, -4));

                    if (!empty($name)) {
                        $type = $this->getReturnTypeFromDocBlock($method);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($methodName, 'set') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && $method !== 'setAttr'
                ) {
                    //修改器
                    $name = Loader::parseName(substr($method, 3, -4));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } else {
                    //关联对象
                    try {
                        $return = $method->invoke($model);

                        if ($return instanceof Relation) {
                            $name = Loader::parseName($methodName);
                            if ($return instanceof HasOne || $return instanceof BelongsTo) {
                                $this->setProperty($name, "\\" . $return->getModel(), true, null);
                            }

                            if ($return instanceof HasMany || $return instanceof HasManyThrough || $return instanceof BelongsToMany) {
                                $this->setProperty($name, "\\" . "{$return->getModel()}[]", true, null);
                            }
                        }
                    } catch (\Exception $e) {

                    }
                }
            }
        }

    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {

        $reflection  = new \ReflectionClass($class);
        $namespace   = $reflection->getNamespaceName();
        $classname   = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $context     = new Context($namespace);
        $summary     = "Class {$classname}";

        $fqsenResolver      = new FqsenResolver();
        $tagFactory         = new StandardTagFactory($fqsenResolver);
        $descriptionFactory = new DescriptionFactory($tagFactory);
        $typeResolver       = new TypeResolver($fqsenResolver);

        $properties = [];
        $tags       = [];
        if (!$this->reset) {
            try {
                //读取文件注释
                $phpdoc = DocBlockFactory::createInstance()->create($reflection, $context);

                $summary    = $phpdoc->getSummary();
                $properties = [];
                $tags       = $phpdoc->getTags();
                foreach ($tags as $key => $tag) {
                    if ($tag instanceof DocBlock\Tags\Property || $tag instanceof DocBlock\Tags\PropertyRead || $tag instanceof DocBlock\Tags\PropertyWrite) {
                        if ($this->overwrite && array_key_exists($tag->getVariableName(), $this->properties)) {
                            //覆盖原来的
                            unset($tags[$key]);
                        } else {
                            $properties[] = $tag->getVariableName();
                        }
                    }
                }
            } catch (\InvalidArgumentException $e) {

            }
        }
        foreach ($this->properties as $name => $property) {
            if (in_array($name, $properties)) {
                continue;
            }
            $name = "\${$name}";
            $body = trim("{$property['type']} {$name} {$property['comment']}");

            if ($property['read'] && $property['write']) {
                $tag = DocBlock\Tags\Property::create($body, $typeResolver, $descriptionFactory, $context);
            } elseif ($property['write']) {
                $tag = DocBlock\Tags\PropertyWrite::create($body, $typeResolver, $descriptionFactory, $context);
            } else {
                $tag = DocBlock\Tags\PropertyRead::create($body, $typeResolver, $descriptionFactory, $context);
            }

            $tags[] = $tag;
        }

        $phpdoc = new DocBlock($summary, null, $tags, $context);

        $serializer = new DocBlockSerializer();

        $docComment = $serializer->getDocComment($phpdoc);

        $filename = $reflection->getFileName();

        $contents = file_get_contents($filename);
        if ($originalDoc) {
            $contents = str_replace($originalDoc, $docComment, $contents);
        } else {
            $needle  = "class {$classname}";
            $replace = "{$docComment}\nclass {$classname}";
            $pos     = strpos($contents, $needle);
            if ($pos !== false) {
                $contents = substr_replace($contents, $replace, $pos, strlen($needle));
            }
        }
        if (file_put_contents($filename, $contents)) {
            $this->output->info('Written new phpDocBlock to ' . $filename);
        }

    }

    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name]            = [];
            $this->properties[$name]['type']    = 'mixed';
            $this->properties[$name]['read']    = false;
            $this->properties[$name]['write']   = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function getReturnTypeFromDocBlock(\ReflectionMethod $reflection)
    {
        $type = null;
        try {
            $phpdoc = DocBlockFactory::createInstance()->create($reflection, new Context($reflection->getDeclaringClass()->getNamespaceName()));
            if ($phpdoc->hasTag('return')) {
                /** @var DocBlock\Tags\Return_ $returnTag */
                $returnTag = $phpdoc->getTagsByName('return')[0];
                $type      = $returnTag->getType();
                if ($type instanceof This || $type instanceof Static_ || $type instanceof Self_) {
                    $type = "\\" . $reflection->getDeclaringClass()->getName();
                }
            }
        } catch (\InvalidArgumentException $e) {

        }
        return (string) $type;
    }

    /**
     * 自动获取模型
     * @return array
     */
    protected function loadModels()
    {
        $models = [];
        foreach ($this->dirs as $dir) {
            $dir = APP_PATH . '/' . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }
}