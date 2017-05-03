<?php
/**
 * @copyright Copyright (c) 2015 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace koma136\cdn;

use CAssetManager;
use CException;
use Yii;
use CFileHelper;
use Aws\S3\S3Client;

/**
 *
 * S3AssetManager
 *
 * Is a replacement for CAssetManager. It works in conjunction with the cache component to find out whether an asset has
 * already been processed or not. If it haven't then it publishes the asset to configured S3 bucket.
 *
 * This asset is the companion of S3Manager and S3Command. The S3Manager requires this component to publish the assets to
 * the bucket where the assets are shared in a distributed system. It works with version-based assets and realpath
 * hashing.
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 */
class S3AssetManager extends CAssetManager
{

    use AssetManagerTrait;

    /**
     * @var string the region of the bucket
     */
    public $region;
    /**
     * @var string the bucket name to upload to
     */
    public $bucket;
    /**
     * @var string the folder you wish to manage on s3
     */
    public $path;
    /**
     * @var string the "cache" name to track whether we have uploaded a file on S3 or not
     */
    public $cacheComponent = 'cache';
    /**
     * @var S3Client instance
     */
    protected $s3;
    /**
     * @var string the global version to publish. If not set will try to get from Yii::app()->params['assetsVersion'].
     * If the parameter is not set, will not use asset version based and will publish the assets to S3 directly.
     *
     */
    public $assetsVersion;
    /**
     * @var array keeps track of published folders
     */
    private $_published;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if ($this->isDeveloperEnvironment === false) {
            if ($this->bucket === null || $this->key === null || $this->secret === null) {
                throw new CException(
                    '"S3AssetManager.bucket", "S3AssetManager.key" and/or "S3AssetManager.secret" cannot be empty.'
                );
            }
            $this->assetsVersion = $this->assetsVersion !== null
                ? $this->assetsVersion
                : (@Yii::app()->params['assetsVersion'] ?: false);
        }
        parent::init();
    }

    /**
     * Publishes a file or a directory to the specified S3 Bucket. The one CDN will point to.
     * This method will copy the specified asset to a web accessible directory
     * and return the URL for accessing the published asset.
     * <ul>
     * <li>If the asset is a file, its file modification time will be checked
     * to avoid unnecessary file copying;</li>
     * <li>If the asset is a directory, all files and subdirectories under it will
     * be published recursively. Note, in case $forceCopy is false the method only checks the
     * existence of the target directory to avoid repetitive copying.</li>
     * </ul>
     *
     * Note: On rare scenario, a race condition can develop that will lead to a
     * one-time-manifestation of a non-critical problem in the creation of the directory
     * that holds the published assets. This problem can be avoided altogether by 'requesting'
     * in advance all the resources that are supposed to trigger a 'publish()' call, and doing
     * that in the application deployment phase, before system goes live. See more in the following
     * discussion: http://code.google.com/p/yii/issues/detail?id=2579
     *
     * @param string $path the asset (file or directory) to be published
     * @param boolean $hashByName whether the published directory should be named as the hashed basename.
     * If false, the name will be the hash taken from dirname of the path being published and path mtime.
     * Defaults to false. Set true if the path being published is shared among
     * different extensions.
     * @param integer $level level of recursive copying when the asset is a directory.
     * Level -1 means publishing all subdirectories and files;
     * Level 0 means publishing only the files DIRECTLY under the directory;
     * level N means copying those directories that are within N levels.
     * @param boolean $forceCopy whether we should copy the asset file or directory even if it is already
     * published before. In case of publishing a directory old files will not be removed.
     * This parameter is set true mainly during development stage when the original
     * assets are being constantly changed. The consequence is that the performance is degraded,
     * which is not a concern during development, however. Default value of this parameter is null meaning
     * that it's value is controlled by {@link $forceCopy} class property. This parameter has been available
     * since version 1.1.2. Default value has been changed since 1.1.11.
     * Note that this parameter cannot be true when {@link $linkAssets} property has true value too. Otherwise
     * an exception would be thrown. Using this parameter with {@link $linkAssets} property at the same time
     * is illogical because both of them are solving similar tasks but in a different ways. Please refer
     * to the {@link $linkAssets} documentation for more details.
     * @param bool $assetsVersion whether to use version based asset management or not. If null, will default to version
     * set on {$link $assetsVersion}, if you don't wish to use version based asset management use `false`.
     *
     * @return string an absolute URL to the published asset
     * @throws CException if the asset to be published does not exist.
     */
    public function publish($path, $hashByName = false, $level = -1, $forceCopy = null, $assetsVersion = null)
    {
        if ($this->isDeveloperEnvironment) {
            return parent::publish($path, $hashByName, $level, $forceCopy);
        }

        $src = realpath($path);

        if (isset($this->_published[$src])) {
            return $this->_published[$src];

        } elseif ($src !== false) {

            if ($assetsVersion === null) {
                $assetsVersion = $this->assetsVersion;
            }
            Yii::trace("Assets Version: {$assetsVersion}");

            if (is_file($src)) {
                $contentType = CFileHelper::getMimeTypeByExtension($src);
                $filename = basename($src);
                $directory = $this->hash(pathinfo($src, PATHINFO_DIRNAME));
                $directory = $assetsVersion !== false
                    ? $this->path . "/" . $assetsVersion . "/" . $directory // for version based assets
                    : $this->path . "/" . $directory;

                $destFile = $directory . "/" . $filename;
                $cache = $this->getCache();
                $cacheKey = $this->getCacheKey($src, $assetsVersion);

                if ($forceCopy || $cache->get($cacheKey) === false) {

                    if ($this->publishToBucket($destFile, $src, $contentType)) {
                        // flag cache that a file has been uploaded
                        // we may work with versioning... if a library has been updated, make sure you update the version
                        // of the assets file!
                        $cache->set($cacheKey, true, 0);
                        Yii::trace("{$src} sent to S3");
                    } else {
                        throw new CException("Unable to sent asset to S3!");
                    }
                } else {
                    Yii::trace("Returning {$path} from cache.");
                }
                return $this->_published[$src] = $this->getBaseUrl() . $destFile;

            } elseif (is_dir($src)) {
                $directory = $this->hash($src);
                $directory = $this->assetsVersion !== false
                    ? $this->path . "/" . $assetsVersion . "/" . $directory // version based assets
                    : $this->path . "/" . $directory;
                $cache = $this->getCache();
                $cacheKey = $this->getCacheKey($src, $assetsVersion);

                if ($forceCopy || $cache->get($cacheKey) === false) {
                    $files = CFileHelper::findFiles(
                        $src,
                        [
                            'exclude' => $this->excludeFiles,
                            'level' => $level
                        ]
                    );
                    foreach ($files as $file) {
                        $file = realpath($file);
                        $destFile = $directory . "/" . str_replace($src . "/", "", $file);
                        $contentType = CFileHelper::getMimeTypeByExtension($destFile);
                        if ($this->publishToBucket($destFile, $file, $contentType)) {
                            Yii::trace("Sent $destFile to S3");
                        } else {
                            throw new CException("Unable to send assets to S3!");
                        }
                    }
                    $cache->set($cacheKey, true, 0);
                } else {
                    Yii::trace("Returning {$src} from cache");
                }
                return $this->_published[$src] = $this->getBaseUrl() . $directory;
            }
        }
        throw new CException(
            Yii::t('yii', 'The asset "{asset}" to be published does not exist.', array('{asset}' => $path))
        );
    }

    /**
     * @return string the CDN Uri
     */
    public function getBaseUrl()
    {
        if($this->isDeveloperEnvironment) {
            // will call \CAssetManager::getBaseUrl
            return parent::getBaseUrl();
        }

        if ($this->_baseUrl === null) {
            $schema = Yii::app()->getRequest()->isSecureConnection ? 'https' : 'http';
            $baseUrl = "{$schema}://{$this->host}/";
            $this->_baseUrl = $baseUrl;
        }
        return $this->_baseUrl;
    }

    /**
     * Will publish the asset to the bucket
     *
     * @param string $key the AWS API key
     * @param string $sourceFile the path of the cache file (the one that holds version states of assets)
     * @param string $contentType the content type of the file to upload
     *
     * @return bool whether it has been uploaded without any issues
     */
    protected function publishToBucket($key, $sourceFile, $contentType)
    {
        try {
            $this->getS3()->putObject(
                [
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'SourceFile' => $sourceFile,
                    'ContentType' => $contentType
                ]
            );
        } catch (CException $e) {
            Yii::trace($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Returns the cache component
     *
     * @return \CCache instance
     * @throws CException
     */
    protected function getCache()
    {
        if (!Yii::app()->{$this->cacheComponent})
            throw new CException('You need to configure a cache storage or set the variable cacheComponent');

        return Yii::app()->{$this->cacheComponent};
    }

    /**
     * Creates a cache key to store tracked folders or files.
     *
     * @param string $path the folder path used for creating the key
     * @param string $version the version
     *
     * @return string
     */
    protected function getCacheKey($path, $version)
    {
        return $version !== false ? $this->hash($version . '.' . $path) : $this->hash($path);
    }

    /**
     * Returns an instance of a S3 client that will handle the uploads.
     *
     * @return S3Client
     */
    protected function getS3()
    {
        if ($this->s3 === null) {
            $this->s3 = S3Client::factory(
                [
                    'key' => $this->key,
                    'secret' => $this->secret,
                    'region' => $this->region,
                    'signature' => 'v4'
                ]
            );
        }
        return $this->s3;
    }
}