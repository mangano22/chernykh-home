<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController
{
    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function getCheckAction(Request $request): JsonResponse
    {
        $response = [
            'status'  => 'ok',
            'message' => 'Hello there!',
        ];

        return new JsonResponse($response);
    } 
}
