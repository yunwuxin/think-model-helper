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

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
use phpDocumentor\Reflection\DocBlock\StandardTagFactory;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\FqsenResolver;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\This;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use think\App;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\helper\Str;
use think\Model;
use think\model\Relation;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use think\model\relation\MorphMany;
use think\model\relation\MorphOne;
use think\model\relation\MorphTo;

class Command extends \think\console\Command
{

    protected $dirs = [];

    protected $properties = [];

    protected $methods = [];

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
            ['model'],
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
            $this->methods    = [];

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
                    $this->getPropertiesFromTable($name, $model);
                    $this->getPropertiesFromMethods($name, $model);
                    $this->createPhpDocs($name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $this->output->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }
    }

    /**
     * 从数据库读取字段信息
     * @param string $class
     * @param Model  $model
     */
    protected function getPropertiesFromTable($class, Model $model)
    {
        $properties = (new ReflectionClass($class))->getDefaultProperties();

        $dateFormat = empty($properties['dateFormat']) ? $this->app->config->get('database.datetime_format') : $properties['dateFormat'];
        try {
            $fields = $model->getFields();
        } catch (\Exception $e) {
            $this->output->warning($e->getMessage());
        }
        if (!empty($fields)) {
            foreach ($fields as $name => $field) {

                if (in_array($name, (array) $properties['disuse'])) {
                    continue;
                }

                if (in_array($name, [$properties['createTime'], $properties['updateTime']])) {
                    if (false !== strpos($dateFormat, '\\')) {
                        $type = "\\" . $dateFormat;
                    } else {
                        $type = 'string';
                    }
                } elseif (!empty($properties['type'][$name])) {

                    $type = $properties['type'][$name];

                    if (is_array($type)) {
                        list($type, $param) = $type;
                    } elseif (strpos($type, ':')) {
                        list($type, $param) = explode(':', $type, 2);
                    }

                    switch ($type) {
                        case 'timestamp':
                        case 'datetime':
                            $format = !empty($param) ? $param : $dateFormat;

                            if (false !== strpos($format, '\\')) {
                                $type = "\\" . $format;
                            } else {
                                $type = 'string';
                            }
                            break;
                        case 'json':
                            $type = 'array';
                            break;
                        case 'serialize':
                            $type = 'mixed';
                            break;
                        default:
                            if (false !== strpos($type, '\\')) {
                                $type = "\\" . $type;
                            }
                    }
                } else {
                    if (!preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*(.+)*$/', $field['type'], $matches)) {
                        continue;
                    }
                    $limit     = null;
                    $precision = null;
                    $type      = $matches[1];
                    if (count($matches) > 2) {
                        $limit = $matches[3] ? (int) $matches[3] : null;
                    }

                    if ($type === 'tinyint' && $limit === 1) {
                        $type = 'boolean';
                    }

                    switch ($type) {
                        case 'varchar':
                        case 'char':
                        case 'tinytext':
                        case 'mediumtext':
                        case 'longtext':
                        case 'text':
                        case 'timestamp':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                        case 'set':
                        case 'enum':
                            $type = 'string';
                            break;
                        case 'tinyint':
                        case 'smallint':
                        case 'mediumint':
                        case 'int':
                        case 'bigint':
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
                }
                $comment = $field['comment'];
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
        $classRef = new \ReflectionClass($class);
        $methods  = $classRef->getMethods();

        foreach ($methods as $method) {

            if ($method->getDeclaringClass()->getName() == $classRef->getName()) {

                $methodName = $method->getName();
                if (Str::startsWith($methodName, 'get') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && 'getAttr' !== $methodName) {
                    //获取器
                    $name = App::parseName(substr($methodName, 3, -4));

                    if (!empty($name)) {
                        $type = $this->getReturnTypeFromDocBlock($method);
                        $this->setProperty($name, $type, true, null);
                    }
                } elseif (Str::startsWith($methodName, 'set') && Str::endsWith(
                        $methodName,
                        'Attr'
                    ) && 'setAttr' !== $methodName) {
                    //修改器
                    $name = App::parseName(substr($methodName, 3, -4));
                    if (!empty($name)) {
                        $this->setProperty($name, null, null, true);
                    }
                } elseif (Str::startsWith($methodName, 'scope')) {
                    //查询范围
                    $name = App::parseName(substr($methodName, 5), 1, false);

                    if (!empty($name)) {
                        $args = $this->getParameters($method);
                        array_shift($args);
                        $this->setMethod($name, "\\think\\db\\Query", $args);
                    }
                } elseif ($method->isPublic() && $method->getNumberOfRequiredParameters() == 0) {
                    //关联对象
                    try {
                        $return = $method->invoke($model);

                        if ($return instanceof Relation) {

                            $name = App::parseName($methodName);
                            if ($return instanceof HasOne || $return instanceof BelongsTo || $return instanceof MorphOne) {
                                $this->setProperty($name, "\\" . get_class($return->getModel()), true, null);
                            }

                            if ($return instanceof HasMany || $return instanceof HasManyThrough || $return instanceof BelongsToMany) {
                                $this->setProperty($name, "\\" . get_class($return->getModel()) . "[]", true, null);
                            }

                            if ($return instanceof MorphTo || $return instanceof MorphMany) {
                                $this->setProperty($name, "mixed", true, null);
                            }
                        }
                    } catch (\Exception $e) {
                    } catch (\Throwable $e) {
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
        $methods    = [];
        $tags       = [];
        if (!$this->reset) {
            try {
                //读取文件注释
                $phpdoc = DocBlockFactory::createInstance()->create($reflection, $context);

                $summary    = $phpdoc->getSummary();
                $properties = [];
                $methods    = [];
                $tags       = $phpdoc->getTags();
                foreach ($tags as $key => $tag) {
                    if ($tag instanceof DocBlock\Tags\Property || $tag instanceof DocBlock\Tags\PropertyRead || $tag instanceof DocBlock\Tags\PropertyWrite) {
                        if ($this->overwrite && array_key_exists($tag->getVariableName(), $this->properties)) {
                            //覆盖原来的
                            unset($tags[$key]);
                        } else {
                            $properties[] = $tag->getVariableName();
                        }
                    } elseif ($tag instanceof DocBlock\Tags\Method) {
                        if ($this->overwrite && array_key_exists($tag->getMethodName(), $this->methods)) {
                            //覆盖原来的
                            unset($tags[$key]);
                        } else {
                            $methods[] = $tag->getMethodName();
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

        ksort($this->methods);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }

            $arguments = implode(', ', $method['arguments']);

            $tag    = DocBlock\Tags\Method::create("static {$method['type']} {$name}({$arguments})", $typeResolver, $descriptionFactory, $context);
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
            $replace = "{$docComment}" . PHP_EOL . "class {$classname}";
            $pos     = strpos($contents, $needle);
            if (false !== $pos) {
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
        if (null !== $type) {
            $this->properties[$name]['type'] = $type;
        }
        if (null !== $read) {
            $this->properties[$name]['read'] = $read;
        }
        if (null !== $write) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = [])
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);
        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name]              = [];
            $this->methods[$name]['type']      = $type;
            $this->methods[$name]['arguments'] = $arguments;
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
        return is_null($type) ? null : (string) $type;
    }

    /**
     * @param ReflectionMethod $method
     * @return array
     */
    protected function getParameters($method)
    {
        //Loop through the default values for paremeters, and make the correct output string
        $params            = [];
        $paramsWithDefault = [];
        /** @var \ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramClass = $param->getClass();
            $paramStr   = (!is_null($paramClass) ? '\\' . $paramClass->getName() . ' ' : '') . '$' . $param->getName();
            $params[]   = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_int($default)) {
                    //$default = $default;
                } else {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * 自动获取模型
     * @return array
     */
    protected function loadModels()
    {
        $models = [];
        foreach ($this->dirs as $dir) {
            $dir = $this->app->getBasePath() . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }
}
