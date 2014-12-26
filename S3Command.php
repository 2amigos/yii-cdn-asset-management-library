<?php
/**
 * @copyright Copyright (c) 2015 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace dosamigos\cdn;

use Yii;
use CConsoleCommand;

/**
 * S3Command
 *
 * This command uses an S3Manager to handle publishing of application assets. It can be used to work with for version
 * based or `realpath` asset publishing.
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 * @package dosamigos\cdn
 */
class S3Command extends CConsoleCommand
{

    /**
     * @var string
     */
    public $manager = 'cdnManager';

    /**
     * Publishes assets to S3.
     *
     * @param bool $useVersionCache whether to use version based asset pattern or not
     *
     * @throws \CException
     */
    public function actionPublish($useVersionCache = false)
    {
        echo "\nPublishing assets to S3\n";

        Yii::app()->cache->flush(); // should be flash the cache contents?
        $time_start = microtime(true);
        $useVersionCache = $useVersionCache ? true : false;
        /** @var Manager $manager */
        $manager = Yii::app()->getComponent($this->manager);

        $manager->publishAssets($useVersionCache);

        $time_end = microtime(true);
        $time = $time_end - $time_start;

        echo "\nDone in $time seconds\n";
    }

    /**
     * Invalidates assets on CDN
     *
     * @throws \CException
     */
    public function actionInvalidate() {
        echo "\nInvalidating assets on CloudFront\n";

        /** @var Manager $manager */
        $manager = Yii::app()->getComponent($this->manager);
        $manager->invalidateAssets();
        echo "\nDone invalidation process\n";
    }
}