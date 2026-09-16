<?php

declare(strict_types=1);

namespace App\Modules\Installation;

use Illuminate\Contracts\Config\Repository;
use PDO;
use RuntimeException;
use stdClass;

final readonly class InstallationSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $sslmode,
        public string $disk,
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKey,
        public string $secretKey,
        public bool $pathStyle,
    ) {}

    public static function fromObject(stdClass $data): self
    {
        foreach (['host', 'database', 'username', 'password', 'sslmode', 'disk', 'endpoint', 'region', 'bucket', 'accessKey', 'secretKey'] as $field) {
            if (! isset($data->$field) || ! is_string($data->$field)) {
                throw new RuntimeException('Installatieconfiguratie is beschadigd; herstel de private configuratie uit de backup.');
            }
        }
        if (! isset($data->port, $data->pathStyle) || ! is_int($data->port) || ! is_bool($data->pathStyle)) {
            throw new RuntimeException('Installatieconfiguratie heeft een ongeldige structuur.');
        }

        return new self($data->host, $data->port, $data->database, $data->username, $data->password, $data->sslmode, $data->disk, $data->endpoint, $data->region, $data->bucket, $data->accessKey, $data->secretKey, $data->pathStyle);
    }

    public function apply(Repository $config): void
    {
        $config->set('database.default', 'pgsql');
        $config->set('database.connections.pgsql', array_replace($config->get('database.connections.pgsql'), [
            'url' => null,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'sslmode' => $this->sslmode,
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ]));
        $config->set('filesystems.default', $this->disk);
        $config->set('filesystems.disks.local.serve', false);
        $config->set('filesystems.disks.local.throw', true);
        $config->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => $this->accessKey,
            'secret' => $this->secretKey,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->pathStyle,
            'visibility' => 'private',
            'throw' => true,
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ]);
    }

    public function fingerprint(string $email): string
    {
        return hash('sha256', json_encode([$this, strtolower($email)], JSON_THROW_ON_ERROR));
    }
}
