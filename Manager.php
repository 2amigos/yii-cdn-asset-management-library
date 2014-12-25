<?php
/**
 * @copyright Copyright (c) 2013 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace dosamigos\cdn;

use Yii;
use CException;
use CApplicationComponent;
use CConsoleApplication;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Manager
 *
 * This component can be used whether with S3Command and S3AssetManager, or CdnAssetManager. With the first ones should
 * be used in order to publish and get the proper assets URL, with the latest to invalidate assets on CDN.
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 * @package dosamigos\cdn
 */
class Manager extends CApplicationComponent
{
    /**
     * @var array
     */
    public $assetsPaths = array();
    /**
     * @var string
     */
    public $runtimePath = 'application.runtime';
    /**
     * @var array the files to ignore
     */
    public $ignore = array();
    /**
     * @var int the start version of assets if used version based assets.
     */
    public $startVersion;
    /**
     * @var string the id of the asset manager component id that handles publishing to S3.
     */
    public $assetManagerComponentId;
    /**
     * @var S3AssetManager instance that handles publishing to S3.
     */
    protected $assetManager;
    /**
     * @var string the file cache
     */
    private $fileCache;
    /**
     * @var string the path of the file cache
     */
    private $cachePath;
    /**
     * @var int the timestamp when the file has been checked for changes
     */
    private $timeStamp;

    /**
     * @inheritdoc
     * @throws CException
     */
    public function init()
    {

        if ($this->assetManagerComponentId === null) {
            throw new CException('"remoteAssetManager" cannot be null.');
        }

        $this->assetManager = Yii::app()->getComponent($this->assetManagerComponentId);

        $this->timeStamp = time();
        $this->ignore = $this->extend(
            array(
                // ignore hidden files
                '\.*',
                '*/\.*'
            ),
            $this->ignore
        );

        // convert the ignore patterns to regular expressions
        foreach ($this->ignore as &$ignore) {
            $ignore = $this->convertPatternToRegex($ignore);
        }

        $this->cachePath = Yii::getPathOfAlias($this->runtimePath) . '/cdnManager.cache';

        parent::init();
    }

    /**
     * Echoes formatted messages to console
     *
     * @param string $msg the message to echo
     * @param string|null $color whether to format the message inb color or not
     */
    public function consoleEcho($msg, $color = null)
    {
        if (Yii::app() instanceof CConsoleApplication) {
            if (!is_null($color)) {
                echo "\033[{$color}m" . $msg . "\033[0m";
            } else {
                echo $msg;
            }
        }
    }

    /**
     * Publishes assets
     *
     * @param bool $useVersionCache
     *
     * @throws CException
     */
    public function publishAssets($useVersionCache = false)
    {
        if (!($this->assetManager instanceof S3AssetManager)) {
            throw new CException('"assetManager" must be an instance of CdnAssetManager');
        }

        foreach ($this->assetsPaths as $alias) {
            $path = Yii::getPathOfAlias($alias);
            if ($useVersionCache) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($files as $file) {
                    if ($this->isFileIgnored($file)) {
                        $this->consoleEcho("Ignored ", "0;31");
                        $this->consoleEcho($file->getFileName() . "\r\n");
                        continue;
                    }

                    $this->publishFile($file, $path);
                }
            } else {
                $this->consoleEcho("Publishing directory without cache {$path}\n", "0;32");
                $this->assetManager->publish(
                    $path,
                    false,
                    -1,
                    true,
                    false
                );
            }

        }
    }

    /**
     * Returns the file where it stores the cached information about the versions of asset files.
     *
     * @return array|mixed
     */
    public function getFileCache()
    {
        // get the array of published assets
        if ($this->fileCache === null) {
            $this->fileCache = file_exists($this->cachePath) ? unserialize(
                file_get_contents($this->cachePath)
            ) : array();
        }
        return $this->fileCache;
    }

    /**
     * Removes the file that stores cached data of processed files in order to do the process from the begginning.
     */
    public function resetFileCache()
    {

        if (is_file($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    /**
     * Gets the correct version of an asset to get the latest version from cache.
     *
     * @param string $asset the asset file
     *
     * @return bool
     */
    public function getAsset($asset)
    {
        $cache = $this->getFileCache();
        $asset = realpath($asset);
        return array_key_exists($asset, $cache) ? $cache[$asset]['pub'] : false;
    }

    /**
     * @param \SplFileInfo $file
     * @param string $path
     */
    protected function publishFile($file, $path)
    {
        $path = realpath($path);
        $cache = $this->getFileCache();

        $relative = str_replace($path, "", $file->getPathname());

        if (!array_key_exists($relative, $cache)) {
            $fileCache = array(
                'sha' => 'cdnManager',
                'ver' => $this->startVersion,
                'run' => 0
            );
        } else {
            $fileCache = $cache[$relative];
        }

        if ($fileCache['sha'] !== sha1_file($file->getPathname())) {
            $this->consoleEcho("Updating ", "0;32");
            $this->consoleEcho($file->getFileName() . " \r\n", true);
            $fileCache['ver'] = $fileCache['ver'] + 1;
            $fileCache['sha'] = sha1_file($file->getPathname());

            $fileCache['pub'] = $this->assetManager->publish(
                $file->getRealPath(),
                false,
                -1,
                true,
                $fileCache['ver']
            );

        } else {
            $this->consoleEcho("No Change ", "0;33");
            $this->consoleEcho($file->getFileName() . "\r\n");
        }

        $fileCache['run'] = $this->timeStamp;
        $cache[$relative] = $fileCache;

        $this->setFileCache($cache);

    }

    /**
     * @param $file
     *
     * @return bool
     */
    protected function isFileIgnored($file)
    {
        if (is_dir($file)) {
            return true;
        }
        foreach ($this->ignore as $ignore) {
            if ((bool)preg_match('/^' . $ignore . '$/i', $file)) {
                return true;
            }
        }
        return false;

    }

    /**
     * @param array $cache
     */
    protected function setFileCache($cache = array())
    {
        $this->fileCache = $cache;
        file_put_contents($this->cachePath, serialize($cache));
    }

    /**
     * @param array $defaults
     * @param array $input
     *
     * @return array
     */
    protected function extend($defaults = array(), $input = array())
    {
        if (isset($input)) {
            foreach ($defaults as $key => $value) {
                if (!array_key_exists($key, $input)) {
                    $input[$key] = $value;
                }
            }
        } else {
            $input = $defaults;
        }
        return $input;
    }

    /**
     * @param $pattern
     *
     * @return mixed
     */
    protected function convertPatternToRegex($pattern)
    {
        $pattern = preg_replace_callback(
            '/([^*])/',
            function ($matches) {
                return preg_quote($matches[0], "/");
            },
            $pattern
        );
        $pattern = str_replace('*', '.*', $pattern);
        return $pattern;
    }
}