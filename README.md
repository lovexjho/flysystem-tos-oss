# hyperf文件系统添加火山引擎对象存储支持

`config\file.php`中，添加`tos`配置
```php
'tos' => [
            'driver' => TosAdapterFactory::class,
            'region' => env('TOS_REGION'),
            'bucket' => env('TOS_BUCKET'),
            'endpoint' => env('TOS_ENDPOINT'),
            'ak' => env('AK'),
            'sk' => env('SK'),
            'securityToken' => env('SECURITY_TOKEN'),
            'connectionTimeout' => 10000, // 毫秒
            'socketTimeout' => 30000, // 毫秒,
            'enableVerifySSL' => true
        ]
```
## tos预签名
```php
$filesystemFactory = make(\Hyperf\Filesystem\FilesystemFactory::class);
$tos = $filesystemFactory->getAdapter('tos');

$preSign = $tos->preSignedURL($path, new \League\Flysystem\Config([
'httpMethod' => \Tos\Model\Enum::HttpMethodPut,
'expires' => 3600
]));

var_dump($preSign);
```