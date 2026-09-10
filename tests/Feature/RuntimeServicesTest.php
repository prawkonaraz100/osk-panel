<?php

namespace Tests\Feature;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RuntimeServicesTest extends TestCase
{
    public function test_postgresql_is_the_testing_database(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $row = DB::selectOne('select current_database() as database_name');
        $this->assertSame('osk_panel', $row->database_name);
    }

    public function test_redis_is_available_for_cache_queue_and_locks(): void
    {
        $this->assertNotFalse(Redis::connection()->command('ping'));
        Cache::store('redis')->put('s5-env-cache', 'ok', 30);
        $this->assertSame('ok', Cache::store('redis')->get('s5-env-cache'));
        $lock = Cache::store('redis')->lock('s5-env-lock', 10);
        $this->assertTrue($lock->get());
        $lock->release();
        $this->assertIsInt(Queue::connection('redis')->size());
    }

    public function test_s3_compatible_nonproduction_storage_round_trip(): void
    {
        $this->assertIsBool(config('filesystems.disks.s3.use_path_style_endpoint'));
        $this->assertTrue(config('filesystems.disks.s3.use_path_style_endpoint'));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:5000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);

        try {
            $client->headBucket(['Bucket' => 'osk-panel-test']);
        } catch (AwsException) {
            $client->createBucket(['Bucket' => 'osk-panel-test']);
        }

        $disk = Storage::disk('s3');
        $key = 's5-env/smoke.txt';
        $this->assertTrue($disk->put($key, 'environment-ok'));
        $this->assertSame('environment-ok', $disk->get($key));
        $this->assertTrue($disk->delete($key));
    }
}
