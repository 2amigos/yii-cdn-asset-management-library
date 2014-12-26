CDN Asset Management Library for Yii 1
======================================

This library is a set of tools that will help you manage your assets with Amazon CloudFront services, whether they are 
pointing CloudFront to the server where the application assets are on to a shared S3 server instance.

Installation
------------
The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require 2amigos/yii-cdn-asset-management-library "*"
```
or add

```json
"2amigos/yii-cdn-asset-management-library" : "*"
```

to the require section of your application's `composer.json` file.

Introduction
------------
CloudFront is a content delivery service offered by Amazon web services (AWS). It serves static contents using a 
global network with many edge locations. Using these locations. CloudFront accelerates delivery of content by serving 
cached copies of the content objects from the nearest location. You can design CDN to be used with Amazon S3 or any 
other custom origin server.

That ***IS AWESOME***, but that awesomeness is not all that when your architecture requires more than one server to 
handle your web traffic, and if those instances are created automatically according to that traffic, you will soon face 
the issue of 404 Not Found exceptions. 

That is due to the Yii's asset pipeline and the nature of distributed systems. When a request is made, CAssetManager 
will generate all required asset files under current instance `assets` folder, but the HTTP requests of those static 
files under assets could be processed by another web server instance.
 
We checked some solutions out there such as [Yii-CloudFrontAssetManager](https://github.com/iamfrankhe/Yii-CloudFrontAssetManager) 
and [Mantis Manager](https://github.com/aarondfrancis/mantis-manager) but the first was working with CDN invalidation 
and assets hashes by name (which means that you are forced to invalidate the files when you make a change otherwise CDN
will serve the file on its cache and that comes with a high price when you do have lots of asset files), and Mantis was 
doing far too many things that were not suitable for our project plus the fact that, with a system with so many asset 
files like we were building we couldn't rely on its clever system to find out whether a file had changed or not and 
publish and/or serve the proper version of the file.

What we did is finally a mix of all the things proposed by those great extensions and found on our research: 

- Version Based Asset Pattern: Using an S3 instance as an asset distribution system
- Real Path Hash Pattern: Using an S3 instance as an asset distribution system
- Point to Custom Origin Pattern: Using CDN to point to one of web app instances and update hashes by `realpath` of files

Due that on each deployment to production a new folder was created based on its timestamp, we used the last mentioned 
pattern for its simplicity. 

IMPORTANT
---------
Some extensions do work with the `YII_DEBUG` Yii constant in order to force copy the assets, that means for the 
`S3AssetManager` to publish to S3 Bucket all the time the files. So make sure you do have that constant set to `false`.

Also, make sure you have configured a proper `CCache` Component in your application, that is highly important as it tells,
whether the asset file has been already processed (means published to S3) or not. We have configured `CMemcache`.

Version Based Asset Pattern
--------------------------- 
This technique requires a file that keeps cached information about changes on the files under specified folders. When 
a file changes (based on the result of hashing its contents), it updates the version of the file in cache and publishes 
the file to S3 bucket.

Please note that this is only a good approach if you are not having too many asset files. But when you have far too many 
and is difficult for you to minify and concatenate scripts (ie too many extensions with dynamic assets), then this 
pattern is not a good solution due that the `Manager` is the one who serves the correct file version and for that checks 
the file cache to serve it.

This pattern consists of the configuration and usage of the following three components: 

- S3Command: Updates S3 bucket with new|modified files of specified paths
- Manager: Checks if they are new files or new|modified ones and publishes to the specified S3 bucket 
- S3AssetManager: Publishes files to S3. It handles `dynamic` and `static` assets publishing.

**Configuration and Usage** 

On your config file: 

```php 
// In Components section
// ...
'cdnManager' => array( // make sure you set this name to the one that you are going to use wit S3Command
    'class' => 'dosamigos\cdn\Manager',
    'remoteAssetManagerComponent' => 'assetManager',
    'startVersion' => 1, // the starting version of assets when it has no records in file cache
    'assetsPaths' => array( // the 'static' assets you wish to publish. They must be "aliases"
        'webroot.assets',
        'common.extensions.widgets.assets',
        'vendor.yiisoft.yii.framework.zii.widgets.assets', // you can also manage vendor 'dynamic' assets
        'vendor.yiisoft.yii.framework.web.js.source',
    )
),
'assetManager' => array(
    'class' => 'dosamigos\cdn\S3AssetManager',
    'key' => '{YOUR_AWS_API_KEY}',
    'secret' => '{YOUR_AWS_API_SECRET}',
    'host' => '{YOUR_CLOUDFRONT_ID}.cloudfront.net',
    'bucket' =>'{YOUR_BUCKET_NAME}',
    'path' => 'assets', // The folder you publish your assets on S3
    'region' => 'us-east-1',
    'assetsVersion' => 1 // Your 'dynamic' assets version
),

```

Once you have setup your components you can make use of the command to check periodically your asset files: 

``` 
./yiic S3 --manager=cdnManager publish --userVersionCache=1
```

The manager will then go throughout all specified `assetsPaths` aliases on its configuration, update the cache file and, 
if required, publish to S3 the files.

In order to get an asset url you will have to make use of the `Manager::getAsset` method. For example, on a base child 
CController component: 
 
```php 
class Controller extends CController 
{
    public function asset($asset) {
        return Yii::app()->cdnManager->getAsset($asset);
    }
}

``` 

The reason of this extra step, is that is the `Manager` the component that reads from saved file cache, check latest 
file version url and serves if its found.

Real Path Hash Pattern
----------------------
This pattern works just if your application's full path changes on each deployment to production servers. For example, 
in our case, the latest production full path was changing according to the timestamp of the deployment: 

```
/var/full/path/to/application/20141223113901/
```

With that in mind, it was easy to publish assets according to the hash created based on the assets full path as that was 
changing on each deployment. The only issue is that you will end up with many assets on S3 that you may not use, but it 
won't be too hard to write a command that cleans assets that are not being in use on your S3 bucket. 

**Configuration and Usage**  

The configuration is similar to the one when using the *Version Based Asset Pattern* but we must set `assetsVersion` to 
false: 

```php 
'cdnManager' => array( // make sure you set this name to the one that you are going to use wit S3Command
    'class' => 'dosamigos\cdn\Manager',
    'remoteAssetManagerComponent' => 'assetManager',
    'assetsPaths' => array( // the 'static' assets you wish to publish. They must be "aliases"
        'webroot.assets',
        'common.extensions.widgets.assets',
        'vendor.yiisoft.yii.framework.zii.widgets.assets', // you can also manage vendor 'dynamic' assets
        'vendor.yiisoft.yii.framework.web.js.source',
    )
),
'assetManager' => array(
    'class' => 'dosamigos\cdn\S3AssetManager',
    'key' => '{YOUR_AWS_API_KEY}',
    'secret' => '{YOUR_AWS_API_SECRET}',
    'host' => '{YOUR_CLOUDFRONT_ID}.cloudfront.net',
    'bucket' =>'{YOUR_BUCKET_NAME}',
    'path' => 'assets', // The folder you publish your assets on S3
    'region' => 'us-east-1',
    'assetsVersion' => false
),

// and for the command in section "commandMap"

'commandMap' => array(
    'S3' => array( // you can alias your command the way you wish
        // if "vendor" alias is present!
        'class' => 'dosamigos\cdn\S3Command',
    ),
    'migrate' => array(
        'class' => 'system.cli.commands.MigrateCommand',
        'migrationPath' => 'application.migrations'
    )
)
``` 

When we turn off `assetsVersion` the `Manager` instance will publish assets to your S3 bucket based on the changes of 
the path name to your assets. That means, that the following command should be used post-deploy on your production 
server:
  
``` 
./yiic S3 --manager=cdnManager publish
```


Point to Custom Origin Pattern
------------------------------
On this case, instead of using an S3 instance, what you do is to point CDN to one of your web instances and make sure 
that the contents your assets folder are also published on each deployment, that is, if you use git then add them to 
the repository so you can keep track of the changes. 

The `CdnAssetManager` what it does is to tell CDN to serve the asset from the pointed web instance. Now, if you do have 
continuous full path changes (as described above) is all fine, but if not, you will be forced to use the invalidation 
command and that comes with a price. On a distributed system, it won't work as expected, as it could lead to broken app 
till the CDN updates (5~10 minutes).


**Configuration and Usage**  

The only thing required for this is to configure the asset manager, 


```php 
'cdnManager' => array( // make sure you set this name to the one that you are going to use wit S3Command
    'class' => 'dosamigos\cdn\Manager',
    'remoteAssetManagerComponent' => 'assetManager',
    'assetsPaths' => array( // the 'static' assets you wish to invalidate. They must be "aliases"
        'webroot.assets',
        'common.extensions.widgets.assets',
        'vendor.yiisoft.yii.framework.zii.widgets.assets', // you can also manage vendor 'dynamic' assets
        'vendor.yiisoft.yii.framework.web.js.source',
    )
),
'assetManager' => array(
    'class' => 'dosamigos\cdn\CdnAssetManager',
    'key' => '{YOUR_AWS_API_KEY}', // if you wish to use invalidation
    'secret' => '{YOUR_AWS_API_SECRET}', // if you wish to use invalidation
    'host' => '{YOUR_CLOUDFRONT_ID}.cloudfront.net',
),
``` 

The command to invalidate your assets is: 

``` 
./yiic S3 --manager=cdnManager invalidate
```

About CDN Invalidation
----------------------
In CloudFront cache invalidation is a costly operation with various restrictions. First of all, you can run only 3 
invalidation requests at any given time. Second, in each validation request you can included maximum of 1000 files. 
Third, invalidation takes time propagate across all edge locations (5~10 minutes). The change in CloudFront distribution 
will eventually be consistent but not immediately. In terms of cost, in a given month invalidation of 1000 files is free 
after that you have to pay per file for each file listed in your invalidation requests.

So beware how you use it... 

 
> [![2amigOS!](http://www.gravatar.com/avatar/55363394d72945ff7ed312556ec041e0.png)](http://www.2amigos.us)  
<i>Web development has never been so fun!</i>  
[www.2amigos.us](http://www.2amigos.us)


