<?php

namespace Lovexjho\FlysystemTosOss;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use Psr\Http\Message\StreamInterface;
use Tos\Model\CopyObjectInput;
use Tos\Model\DeleteObjectInput;
use Tos\Model\Enum;
use Tos\Model\GetObjectACLInput;
use Tos\Model\GetObjectInput;
use Tos\Model\GetObjectOutput;
use Tos\Model\ListObjectsInput;
use Tos\Model\PreSignedURLInput;
use Tos\Model\PutObjectACLInput;
use Tos\Model\PutObjectInput;
use Tos\TosClient;
use Tos\Exception\TosServerException;

class TosAdapter implements FilesystemAdapter
{
    protected TosClient $tosClient;
    protected string    $bucket;
    protected array     $config = [];

    public function __construct(array $options)
    {
        $this->config = $options;
        $this->bucket = $options['bucket'];
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->getMetadata($path) !== false;
        }catch (TosServerException $e) {
            return false;
        }
    }

    protected function getMetadata($path): bool|FileAttributes
    {
        $objectOutput = $this->getObjectOutput($path);

        return new FileAttributes(
            $path,
            $objectOutput->getContent()->getSize(),
            null,
            $objectOutput->getLastModified(),
            $objectOutput->getContentType(),
        );
    }

    protected function getObjectOutput(string $path): GetObjectOutput
    {
        $input = new GetObjectInput($this->bucket, $path);

        return $this->getTosClient()->getObject($input);
    }

    protected function getTosClient(): TosClient
    {
        return $this->tosClient ?? $this->tosClient = new TosClient($this->config);
    }

    /**
     * 生成预签名URL
     * @param $path
     * @param $httpMethod
     * @param int $expire
     * @return array
     */
    public function preSignedURL($path, Config $config): array
    {
        $input = new PreSignedURLInput();

        if ($httpMethod = $config->get('httpMethod')) {
            $input->setHttpMethod($httpMethod);
        }

        if ($domain = $config->get('cname')) {
            $input->setAlternativeEndpoint($domain);
        } else {
            $input->setBucket($this->bucket);
        }

        $input->setKey($path);

        $input->setExpires($config->get('expires') ?? 3600);
        if ($tosProcess = $config->get('x-tos-process')) {
            $input->setQuery([
                'x-tos-process' => $tosProcess
            ]);
        }

        $output = $this->getTosClient()->preSignedURL($input);
        return [
            'signUrl' => $output->getSignedUrl(),
            'signHeader' => $output->getSignedHeader()
        ];
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $content = $stream->getContents();
        } finally {
            $stream->close();
        }

        return $content;
    }

    public function readStream(string $path): ?StreamInterface
    {
        $output = $this->getObjectOutput($path);

        return $output->getContent();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $objectInput = $this->getObjectInputOption($config);
        $objectInput->setKey($path);
        $objectInput->setContent($contents);

        $this->getTosClient()->putObject($objectInput);

    }

    public function setVisibility(string $path, string $visibility): void
    {
        $objectInput = new PutObjectACLInput($this->bucket, $path);
        $objectInput->setACL($this->normalizeVisibility($visibility));

        $this->getTosClient()->putObjectACL($objectInput);
    }

    protected function normalizeVisibility(string $visibility): string
    {
        return match ($visibility) {
            Visibility::PUBLIC => Enum::ACLPublicRead,
            Visibility::PRIVATE => Enum::ACLPrivate,
            default => Enum::ACLPublicReadWrite,
        };
    }

    public function writeStream(string $path, $contents, Config $config): void
    {

        $objectInput = $this->getObjectInputOption($config);

        $objectInput->setContent($contents);
        $objectInput->setKey($path);


        $this->getTosClient()->putObject($objectInput);
    }

    public function deleteDirectory(string $path): void
    {
        $objectInput = new DeleteObjectInput($this->bucket, $path);

        $this->getTosClient()->deleteObject($objectInput);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $objectInput = new PutObjectInput($this->bucket, $path);

        $this->getTosClient()->putObject($objectInput);
    }

    public function visibility(string $path): FileAttributes
    {
        $objectInput  = new GetObjectACLInput($this->bucket, $path);
        $objectOutput = $this->getTosClient()->getObjectACL($objectInput);

        foreach ($objectOutput->getGrants() as $grant) {
            if ($grant->getPermission() === Enum::ACLPublicRead || $grant->getPermission() === Enum::ACLPublicReadWrite) {
                return new FileAttributes($path, null, Visibility::PUBLIC);
            }
        }

        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $objectInput = new ListObjectsInput($this->bucket);
        $objectInput->setDelimiter('/');
        $objectOutput = $this->getTosClient()->listObjects($objectInput);

        foreach ($objectOutput->getContents() as $content) {
            yield new FileAttributes(
                $content->getKey(),
                $content->getSize(),
                null,
                $content->getLastModified()
            );
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->copy($source, $destination, $config);
        $this->delete($source);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $objectInput = new CopyObjectInput($this->bucket, $source, $this->bucket, $destination);

        $this->getTosClient()->copyObject($objectInput);
    }

    public function delete(string $path): void
    {
        $objectInput = new DeleteObjectInput($this->bucket, $path);

        $this->getTosClient()->deleteObject($objectInput);
    }

    private function getObjectInputOption(Config $config)
    {
        $objectInput = new PutObjectInput($this->bucket);

        if ($cache = $config->get('Cache-Control')) {
            $objectInput->setCacheControl($cache);
        }

        if ($acl = $config->get('tos-acl')) {
            $objectInput->setACL($acl);
        }

        if ($meta = $config->get('meta')) {
            $objectInput->setMeta($meta);
        }

        if ($storageClass = $config->get('StorageClass')) {
            $objectInput->setStorageClass($storageClass);
        }
        
        if ($contentMD5 = $config->get('contentMD5')) {
            $objectInput->setContentMD5($contentMD5);
        }
        
        if ($contentDisposition = $config->get('contentDisposition')) {
            $objectInput->setContentDisposition($contentDisposition);
        }
        
        if ($expires = $config->get('expires')) {
            $objectInput->setExpires($expires);
        }

        return $objectInput;
    }
}