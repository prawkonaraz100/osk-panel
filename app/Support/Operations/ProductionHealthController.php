<?php

namespace App\Support\Operations;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProductionHealthController
{
    public function __construct(
        private readonly ProductionHealthProbe $probe,
    ) {}

    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'osk-panel',
        ]);
    }

    public function ready(): JsonResponse
    {
        $readiness = $this->probe->readiness();

        return response()->json(
            $readiness,
            $readiness['status'] === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
