<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base controller for all /api/v1/ endpoints.
 * Provides consistent response helpers.
 */
abstract class BaseController extends Controller
{
    /**
     * Return a single resource response.
     *
     * @param  JsonResource  $resource
     * @param  int           $status   HTTP status code
     */
    protected function resourceResponse(JsonResource $resource, int $status = 200): JsonResponse
    {
        return $resource->response()->setStatusCode($status);
    }

    /**
     * Return a collection response.
     *
     * @param  ResourceCollection  $collection
     */
    protected function collectionResponse(ResourceCollection $collection): JsonResponse
    {
        return $collection->response();
    }

    /**
     * Return a 204 No Content response.
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return a JSON error response.
     *
     * @param  string  $message
     * @param  int     $status
     * @param  array<string, mixed>  $errors
     */
    protected function errorResponse(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $payload = ['message' => $message];

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
