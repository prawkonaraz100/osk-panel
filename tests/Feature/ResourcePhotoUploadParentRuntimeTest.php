<?php

namespace Tests\Feature;

use App\Modules\ResourcesCore\StaffService;
use App\Modules\ResourcesCore\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FoundationSchema;
use Tests\TestCase;

final class ResourcePhotoUploadParentRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FoundationSchema::reset();

        config([
            'uploads.disk' => 's3',
            'filesystems.disks.s3.bucket' => 'resource-photo-parent-runtime',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.endpoint' => 'http://localhost:5000',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);
    }

    public function test_resource_photo_presign_is_parent_bound_before_ready_asset_attachment(): void
    {
        $actor = FoundationSchema::actor();

        $staff = app(StaffService::class)->create(
            $actor['session_id'],
            [
                'email' => 'resource-photo.staff@example.test',
                'first_name' => 'Photo',
                'last_name' => 'Staff',
                'staff_type_codes' => ['OfficeWorker'],
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );
        $vehicle = app(VehicleService::class)->create(
            $actor['session_id'],
            [
                'registration_number' => 'KRPHOTO1',
                'make' => 'Toyota',
                'model' => 'Yaris',
                'category_ids' => [],
                'location_ids' => [],
            ],
            (string) Str::uuid7(),
        );

        $staffAssetId = $this->presignPhoto(
            $actor['session_id'],
            'staff_photo',
            'staff_profile',
            (string) $staff['id'],
        );
        $vehicleAssetId = $this->presignPhoto(
            $actor['session_id'],
            'vehicle_photo',
            'vehicle',
            (string) $vehicle['id'],
        );

        $this->markReady($staffAssetId);
        $this->markReady($vehicleAssetId);

        $staff = app(StaffService::class)->update(
            $actor['session_id'],
            (string) $staff['id'],
            ['photo_asset_id' => $staffAssetId],
            (string) Str::uuid7(),
        );
        $vehicle = app(VehicleService::class)->update(
            $actor['session_id'],
            (string) $vehicle['id'],
            ['photo_asset_id' => $vehicleAssetId],
            (string) Str::uuid7(),
        );

        $this->assertSame($staffAssetId, $staff['photo_asset_id']);
        $this->assertSame($vehicleAssetId, $vehicle['photo_asset_id']);
        $this->assertStringContainsString(
            '/staff_profile/'.(string) $staff['id'].'/',
            (string) DB::table('file_assets')->where('id', $staffAssetId)->value('storage_key'),
        );
        $this->assertStringContainsString(
            '/vehicle/'.(string) $vehicle['id'].'/',
            (string) DB::table('file_assets')->where('id', $vehicleAssetId)->value('storage_key'),
        );
    }

    private function presignPhoto(
        string $sessionId,
        string $purpose,
        string $parentType,
        string $parentId,
    ): string {
        $response = $this->withSession(['auth_session_id' => $sessionId])
            ->postJson('/api/v1/uploads/presign', [
                'purpose' => $purpose,
                'filename' => $purpose.'.png',
                'declared_mime' => 'image/png',
                'size_bytes' => 1,
                'sha256' => hash('sha256', 'x'),
                'parent_type' => $parentType,
                'parent_id' => $parentId,
            ])
            ->assertCreated();

        return (string) $response->json('upload_id');
    }

    private function markReady(string $assetId): void
    {
        DB::table('file_assets')->where('id', $assetId)->update([
            'status' => 'ready',
            'ready_at' => now(),
            'mime_type_detected' => 'image/png',
        ]);
    }
}
