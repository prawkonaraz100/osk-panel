<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ResourcesAssetsUiContractTest extends TestCase
{
    public function test_confirmed_staff_and_vehicle_photo_fields_use_verified_upload_transport(): void
    {
        $root = dirname(__DIR__, 2);
        $workspace = file_get_contents($root.'/resources/js/modules/ResourcesCore/ResourceWorkspace.vue');
        $uploads = file_get_contents($root.'/resources/js/modules/ResourcesCore/uploads.ts');

        $this->assertIsString($workspace);
        $this->assertIsString($uploads);

        $this->assertStringContainsString("import { uploadResourcePhoto } from './uploads'", $workspace);
        $this->assertStringContainsString("'staff_photo'", $workspace);
        $this->assertStringContainsString("'staff_profile'", $workspace);
        $this->assertStringContainsString("'vehicle_photo'", $workspace);
        $this->assertStringContainsString("'vehicle'", $workspace);
        $this->assertStringContainsString('photo_asset_id: assetId', $workspace);
        $this->assertStringContainsString('readyPhotoAssetId.value = asset.id', $workspace);
        $this->assertStringContainsString('Ponów „Zapisz”', $workspace);
        $this->assertStringContainsString('Usuń obecne zdjęcie z pojazdu', $workspace);

        $this->assertStringContainsString('/api/v1/uploads/presign', $uploads);
        $this->assertStringContainsString('/api/v1/uploads/${presign.data.upload_id}/complete', $uploads);
        $this->assertStringContainsString("method: 'PUT'", $uploads);
        $this->assertStringContainsString("digest('SHA-256'", $uploads);
        $this->assertStringContainsString("completed.data.status !== 'ready'", $uploads);
        $this->assertStringContainsString("image/jpeg", $uploads);
        $this->assertStringContainsString("image/png", $uploads);
        $this->assertStringContainsString("image/webp", $uploads);

        $this->assertStringNotContainsString(
            'Bezpieczny transport pliku zostanie podpięty w dedykowanym module UploadsAssets',
            $workspace,
        );
        $this->assertStringNotContainsString(
            'Transfer pliku zostanie uruchomiony dopiero z bezpiecznym pipeline UploadsAssets',
            $workspace,
        );
        $this->assertStringNotContainsString('/pkk', $workspace);
        $this->assertStringNotContainsString('/pkk', $uploads);
    }
}
