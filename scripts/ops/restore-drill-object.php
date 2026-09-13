<?php

declare(strict_types=1);

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();

$endpoint = $_ENV['AWS_ENDPOINT'] ?? 'http://localhost:5000';
$region = $_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1';
$key = $_ENV['AWS_ACCESS_KEY_ID'] ?? 'test';
$secret = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? 'test';
$bucket = $_ENV['RESTORE_DRILL_BUCKET'] ?? 'osk-panel-restore-drill-ci';
$objectKey = 'formal/restore-drill-evidence.txt';

$client = new S3Client([
    'version' => 'latest',
    'region' => $region,
    'credentials' => ['key' => $key, 'secret' => $secret],
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
]);

try {
    try {
        $client->createBucket(['Bucket' => $bucket]);
    } catch (AwsException $exception) {
        if (! in_array($exception->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
            throw $exception;
        }
    }

    $client->putBucketVersioning([
        'Bucket' => $bucket,
        'VersioningConfiguration' => ['Status' => 'Enabled'],
    ]);

    $versionOne = $client->putObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
        'Body' => 'formal-evidence-v1',
        'ContentType' => 'text/plain',
    ]);
    $versionOneId = (string) ($versionOne['VersionId'] ?? '');
    if ($versionOneId === '') {
        throw new RuntimeException('Object-store drill did not receive a version id.');
    }

    $client->putObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
        'Body' => 'formal-evidence-v2',
        'ContentType' => 'text/plain',
    ]);

    $previous = $client->getObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
        'VersionId' => $versionOneId,
    ]);
    $previousBody = (string) $previous['Body'];
    if ($previousBody !== 'formal-evidence-v1') {
        throw new RuntimeException('Object-store previous version content is invalid.');
    }

    $client->putObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
        'Body' => $previousBody,
        'ContentType' => 'text/plain',
    ]);

    $restored = $client->getObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
    ]);
    if ((string) $restored['Body'] !== 'formal-evidence-v1') {
        throw new RuntimeException('Object-store restored current version is invalid.');
    }

    echo json_encode([
        'provider' => 'moto_s3_emulator',
        'versioning_enabled' => true,
        'previous_version_readable' => true,
        'previous_version_restored_as_current' => true,
        'production_target_evidence' => false,
    ], JSON_THROW_ON_ERROR);
} finally {
    try {
        $versions = $client->listObjectVersions(['Bucket' => $bucket]);
        $objects = [];
        foreach (['Versions', 'DeleteMarkers'] as $collection) {
            foreach (($versions[$collection] ?? []) as $version) {
                if (isset($version['Key'], $version['VersionId'])) {
                    $objects[] = [
                        'Key' => (string) $version['Key'],
                        'VersionId' => (string) $version['VersionId'],
                    ];
                }
            }
        }
        if ($objects !== []) {
            $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => $objects, 'Quiet' => true],
            ]);
        }
        $client->deleteBucket(['Bucket' => $bucket]);
    } catch (Throwable) {
        // Cleanup failure must not hide the primary drill assertion.
    }
}
