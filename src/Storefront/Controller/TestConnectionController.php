<?php

declare(strict_types=1);

namespace VertexTax\Storefront\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VertexTax\Service\Api\VertexApiClient;

#[Route(defaults: ['_routeScope' => ['api']])]
class TestConnectionController extends AbstractController
{
    private VertexApiClient $apiClient;

    public function __construct(VertexApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    #[Route(
        path: '/api/_action/vertex-tax/test-connection',
        name: 'api.action.vertex.tax.test.connection',
        methods: ['POST']
    )]
    public function testConnection(Request $request): JsonResponse
    {
        $salesChannelId = $request->request->get('salesChannelId');
        if ($salesChannelId) {
            $this->apiClient->setSalesChannelId($salesChannelId);
        }

        $result = $this->apiClient->testConnection();

        return new JsonResponse($result);
    }
}
