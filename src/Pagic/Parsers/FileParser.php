<?php

namespace Igniter\Flame\Pagic\Parsers;

use Igniter\Flame\Pagic\Cache\FileSystem;
use Symfony\Component\Yaml\Yaml;

/**
 * FileParser class.
 */
//class FileParser implements ArrayAccess, Countable
class FileParser
{
    const SOURCE_SEPARATOR = '---';

    /**
     * @var \Igniter\Flame\Pagic\Model
     */
    protected $object;

    /**
     * @var FileSystem
     */
    protected static $fileCache;

    /**
     * Parses a page or layout file content.
     * The expected file format is following:
     * <pre>
     * ---
     * Data (frontmatter) section
     * ---
     * PHP code section
     * ---
     * Html markup section
     * </pre>
     * If the content has only 2 sections they are considered as Data and Html.
     * If there is only a single section, it is considered as Html.
     *
     * @param string $content The file content.
     *
     * @return array Returns an array with the following indexes: 'data', 'markup', 'code'.
     * The 'markup' and 'code' elements contain strings. The 'settings' element contains the
     * parsed Data as array. If the content string does not contain a section, the corresponding
     * result element has null value.
     * @throws \Igniter\Flame\Pagic\Exceptions\InvalidFormatException
     */
    public static function parse($content)
    {
        $separator = static::SOURCE_SEPARATOR;

        // Split the document into three sections.
        $doc = explode($separator, $content);

        $count = count($doc);

        $result = [
            'data'   => [],
            'code'   => null,
            'markup' => null,
        ];

        // Only markup
        if ($count <= 2) {
            $result['markup'] = implode($separator, array_slice($doc, 1));
        }
        // Data and markup
        elseif ($count === 3) {
            $frontmatter = trim($doc[1]);
            $result['data'] = Yaml::parse($frontmatter);
            $result['markup'] = implode($separator, array_slice($doc, 2));
        }
        // Data, code and markup
        else {
            $frontmatter = trim($doc[1]);
            $result['data'] = Yaml::parse($frontmatter);
            $result['code'] = trim($doc[2]);
            $result['markup'] = implode($separator, array_slice($doc, 3));
        }

        return $result;
    }

    /**
     * Renders a page or layout object as file content.
     *
     * @param $data
     *
     * @return string
     */
    public static function render($data)
    {
        $code = trim(array_get($data, 'code'));
        $markup = trim(array_get($data, 'markup'));

//        $trim = function (&$values) use (&$trim) {
//            foreach ($values as &$value) {
//                if (!is_array($value)) {
//                    $value = trim($value);
//                }
//                else {
//                    $trim($value);
//                }
//            }
//        };

        $settings = array_get($data, 'data', []);
//        $trim($settings);

        // Build content
        $content = [];

        if ($settings) {
//            $content[] = $iniParser->render($settings);
            $content[] = $settings;
        }

        if ($code) {
            $code = preg_replace('/^\<\?php/', '',
                preg_replace('/^\<\?/', '', preg_replace('/\?>$/', '', $code))
            );

            $code = trim($code, PHP_EOL);
            $content[] = '<?php'.PHP_EOL.$code.PHP_EOL.'?>';
        }

        $content[] = $markup;

        $content = trim(implode(PHP_EOL.self::SOURCE_SEPARATOR.PHP_EOL, $content));

        return $content;
    }

    public function process()
    {
        $fileCache = self::$fileCache;
        $filePath = $this->object->getFilePath();
        $path = $fileCache->getCacheKey($filePath);

        $result = [
            'filePath'  => $path,
            'mTime'     => $this->object->mTime,
            'className' => null,
        ];

        if (is_file($path)) {
            $cachedInfo = $fileCache->getCached($path);
            $hasCache = $cachedInfo !== null;

            if ($hasCache AND $cachedInfo['mTime'] == $this->object->mTime) {
                $result['className'] = $cachedInfo['className'];

                return $result;
            }

            if (!$hasCache AND filemtime($path) >= $this->object->mTime) {
                if ($className = $this->extractClassFromFile($path)) {
                    $cacheItem['className'] = $className;
                    $fileCache->storeCached($filePath, $cacheItem);

                    return $result;
                }
            }
        }

        $result['className'] = $this->compile($path);
        $fileCache->storeCached($path, $result);

        return $result;
    }

    /**
     * Compile a page or layout file content as object.
     *
     * @param $path
     *
     * @return string
     */
    protected function compile($path)
    {
        $code = trim($this->object->code);
        $parentClass = trim($this->object->getCodeClassParent());

        $uniqueName = str_replace('.', '', uniqid('', TRUE)).'_'.md5(mt_rand());
        $className = 'Pagic'.$uniqueName.'Class';

        $code = preg_replace('/^\s*function/m', 'public function', $code);
        $code = preg_replace('/^\<\?php/', '',
            preg_replace('/^\<\?/', '', preg_replace('/\?>$/', '', $code))
        );

        $imports = [];
        $pattern = '/(use\s+[a-z0-9_\\\\]+(\s+as\s+[a-z0-9_]+)?;\n?)/mi';
        preg_match_all($pattern, $code, $imports);
        $code = preg_replace($pattern, '', $code);

        if ($parentClass !== null) {
            $parentClass = ' extends '.$parentClass;
        }

        $fileContents = '<?php '.PHP_EOL;
        foreach ($imports[0] as $namespace) {
            $fileContents .= $namespace;
        }

        $fileContents .= "/* {$this->object->getFilePath()} */".PHP_EOL;
        $fileContents .= 'class '.$className.$parentClass.PHP_EOL;
        $fileContents .= '{'.PHP_EOL;
        $fileContents .= $code.PHP_EOL;
        $fileContents .= '}'.PHP_EOL;

        // Evaluates PHP content in order to detect syntax errors
        eval('?>'.$fileContents);

        self::$fileCache->write($path, $fileContents);

        return $className;
    }

    /**
     * @param \Main\Template\Model The template object to source.
     *
     * @return static
     */
    public static function on($object)
    {
        $instance = new static;

        $instance->object = $object;

        return $instance;
    }

    public static function setCache($fileCache)
    {
        self::$fileCache = $fileCache;
    }

    /**
     * Runs the object's PHP file and returns the corresponding object.
     *
     * @param \Main\Template\Page $page The page.
     * @param \Main\Template\Layout $layout The layout.
     * @param \Main\Classes\MainController $controller The controller.
     *
     * @return mixed
     */
    public function source($page, $layout, $controller)
    {
        $data = $this->process();
        $className = $data['className'];

        $fileCache = self::$fileCache;
        if (!class_exists($className)) {
            $fileCache->load($data['filePath']);
        }

        if (!class_exists($className)) {
            $path = array_get($data, 'filePath', $fileCache->getCacheKey($className));
            if (is_file($path)) {
                if ($className = $this->extractClassFromFile($path)) {
                    return new $className($page, $layout, $controller);
                }

                @unlink($path);
            }

            $data = $this->process();
            $className = $data['className'];
        }

        return new $className($page, $layout, $controller);
    }

    /**
     * Extracts the class name from a cache file
     * @return string
     */
    protected function extractClassFromFile($path)
    {
        $fileContent = file_get_contents($path);
        $matches = [];
        $pattern = '/Pagic\S+_\S+Class/';
        preg_match($pattern, $fileContent, $matches);

        if (!empty($matches[0])) {
            return $matches[0];
        }

        return null;
    }
}