<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

class PrivateStorageConfigurationTest extends TestCase
{
    public function test_default_private_storage_remains_local_for_development(): void
    {
        $this->assertSame('local', config('filesystems.disks.private.driver'));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.private.root'));
        $this->assertSame('private', config('filesystems.disks.private.visibility'));
        $this->assertTrue(config('filesystems.disks.private.throw'));
    }

    public function test_private_disk_can_be_built_for_an_s3_compatible_store(): void
    {
        $disk = Storage::build([
            'driver' => 's3',
            'key' => 'local-test-key',
            'secret' => 'local-test-secret',
            'region' => 'us-east-1',
            'bucket' => 'private-test-bucket',
            'endpoint' => 'https://storage.example.test',
            'use_path_style_endpoint' => true,
            'root' => 'private',
            'visibility' => 'private',
            'throw' => true,
        ]);

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk->getAdapter());
        $this->assertSame('private', $disk->getConfig()['visibility']);
        $this->assertTrue($disk->getConfig()['throw']);
        $this->assertArrayNotHasKey('url', $disk->getConfig());
    }
}
