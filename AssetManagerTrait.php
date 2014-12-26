<?php
/**
 * @copyright Copyright (c) 2015 2amigOS! Consulting Group LLC
 * @link http://2amigos.us
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 */
namespace dosamigos\cdn;

/**
 * AssetManagerTrait
 *
 * Encapsulates common properties and methods for both CdnAssetManager and S3AssetManager.
 *
 * @author Antonio Ramirez <amigo.cobos@gmail.com>
 * @link http://www.ramirezcobos.com/
 * @link http://www.2amigos.us/
 * @package dosamigos\cdn
 */
trait AssetManagerTrait
{
    /**
     * @var bool set to true to make the asset manager components run on
     */
    public $isDeveloperEnvironment = false;
    /**
     * @var string the AWS API key
     */
    public $key;
    /**
     * @var string the AWS API secret
     */
    public $secret;
    /**
     * @var string the url of your bucket or Cloudfront hostname -ie. your-bucket-name.s3.amazonaws.com or
     * your-cloudfront-hostname.cloudfront.net
     */
    public $host;
    /**
     * @var string base web accessible path for storing private files
     */
    private $_baseUrl;

}