<?php
/**
 * @copyright Copyright (c) 2015 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace dosamigos\cdn;

use Yii;
use CException;
use CFileHelper;
use CAssetManager;
use Aws\CloudFront\CloudFrontClient;

/**
 * CdnAssetManager
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 * @package dosamigos\cdn
 */
class CdnAssetManager extends CAssetManager
{
    use AssetManagerTrait;

    /**
     * @var string the CDN distribution id
     */
    public $distribution;
    /**
     * @var string keeps the id of the CDN distribution. Used for invalidation requests.
     */
    protected $cdn;

    /**
     * @inheritdoc
     * @throws CException
     */
    public function init()
    {
        if ($this->isDeveloperEnvironment === false && $this->host === null) {
            throw new CException(
                '"CdnAssetManager.host" cannot be null.'
            );
        }
        parent::init();
    }

    /**
     * Returns the CloudFrontClient
     *
     * @return CloudFrontClient|string
     */
    public function getCloudFrontClient()
    {
        if ($this->cdn === null) {
            $this->cdn = CloudFrontClient::factory(
                [
                    'key' => $this->key,
                    'secret' => $this->secret
                ]
            );
        }

        return $this->cdn;
    }

    /**
     * Invalidates specified asset files from CDN.
     *
     * Important: In CloudFront cache invalidation is a costly operation with various restrictions. First of all, you
     * can run only 3 invalidation requests at any given time. Second, in each validation request you can included
     * maximum of 1000 files. Third, invalidation takes time propagate across all edge locations (5~10 minutes).
     * The change in CloudFront distribution will eventually be consistent but not immediately. In terms of cost, in a
     * given month invalidation of 1000 files is free after that you have to pay per file for each file listed in your
     * invalidation requests.
     *
     * @param array $assetsPaths
     *
     * @throws CException
     */
    public function invalidateAssetsOnCloudFront($assetsPaths = [])
    {
        $paths = [];
        $webRootStrLength = strlen(Yii::getPathOfAlias('webroot'));
        $pathGroups = [];

        // get assets to invalidate and store the relative path
        foreach ($assetsPaths as $path) {
            $files = CFileHelper::findFiles(Yii::getPathOfAlias($path));
            foreach ($files as $file) {
                $paths[] = substr($file, $webRootStrLength);
                if (count($paths) >= 1000) {
                    $pathGroups[] = $paths;
                    $paths = [];
                }
            }
        }

        if (!empty($pathGroups)) {
            if (count($pathGroups) > 3) {
                throw new \CException(
                    "You can only invalidate up to 3000 object URLs at one time. Number requested: " . count(
                        $pathGroups
                    )
                );
            }
            foreach ($pathGroups as $p) {
                $this->createInvalidation($p);
            }
        } else {
            $this->createInvalidation($paths);
        }
    }

    /**
     * Executes the CreateInvalidation operation.
     *
     * @param array $paths the relative
     *
     * @return \Guzzle\Service\Resource\Model
     */
    protected function createInvalidation($paths)
    {
        $cdn = $this->getCloudFrontClient();

        return $cdn->createInvalidation(
            [
                'DistributionId' => $this->distribution,
                'Paths' => [
                    'Quantity' => count($paths),
                    'Items' => $paths
                ],
                'CallerReference' => $this->distribution . date('U')
            ]
        );
    }

    /**
     * Override in order to hash always by name.
     *
     * @param string $file
     * @param bool $hashByName
     *
     * @return string
     */
    protected function generatePath($file, $hashByName = false)
    {

        return $this->isDeveloperEnvironment ? parent::generatePath($file, $hashByName) : $this->hash(basename($file));
    }
}