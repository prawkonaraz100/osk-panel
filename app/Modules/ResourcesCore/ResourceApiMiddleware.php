<?php

namespace App\Modules\ResourcesCore;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResourceApiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = trim((string) $request->header('X-Request-Id'));
        if ($requestId === '' || strlen($requestId) > 64) {
            $requestId = (string) Str::uuid7();
        }
        $request->attributes->set('request_id', $requestId);

        try {
            $response = $next($request);
        } catch (AuthenticationException) {
            $response = $this->error('UNAUTHENTICATED', 'Authentication required.', 401, $requestId);
        } catch (AuthorizationException) {
            $response = $this->error('FORBIDDEN', 'Operation is not permitted.', 403, $requestId);
        } catch (ValidationException $exception) {
            $response = new JsonResponse([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Request validation failed.',
                    'fields' => $exception->errors(),
                    'request_id' => $requestId,
                ],
            ], 422);
        } catch (ResourceDomainException $exception) {
            $response = $this->error($exception->machineCode, $exception->getMessage(), $exception->httpStatus, $requestId);
        } catch (ModelNotFoundException) {
            $response = $this->error('RESOURCE_NOT_FOUND', 'Resource not found.', 404, $requestId);
        } catch (Throwable $exception) {
            report($exception);
            $response = $this->error('INTERNAL_ERROR', 'Unexpected server error.', 500, $requestId);
        }

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function error(string $code, string $message, int $status, string $requestId): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status);
    }
}
