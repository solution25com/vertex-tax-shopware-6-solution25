<?php

declare(strict_types=1);

namespace VertexTax\Storefront\Controller;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Shopware\Core\Defaults;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class TaxLogAdminController extends AbstractController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Preview how many logs a cleanup would delete, without deleting anything.
     */
    #[Route(
        path: '/api/_action/vertex-tax/log/count',
        name: 'api.action.vertex.tax.log.count',
        defaults: ['_acl' => ['vertex_tax_log:delete']],
        methods: ['GET']
    )]
    public function count(Request $request): JsonResponse
    {
        try {
            [$where, $params, $types] = $this->buildFilter(
                (string) $request->query->get('range', ''),
                $request->query->get('from'),
                $request->query->get('to')
            );
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `vertex_tax_log`' . $where,
            $params,
            $types
        );

        return new JsonResponse(['count' => $count]);
    }

    /**
     * Delete logs matching the requested range.
     */
    #[Route(
        path: '/api/_action/vertex-tax/log/delete',
        name: 'api.action.vertex.tax.log.delete',
        defaults: ['_acl' => ['vertex_tax_log:delete']],
        methods: ['POST']
    )]
    public function delete(Request $request): JsonResponse
    {
        try {
            [$where, $params, $types] = $this->buildFilter(
                (string) $request->request->get('range', ''),
                $request->request->get('from'),
                $request->request->get('to')
            );
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $deleted = (int) $this->connection->executeStatement(
            'DELETE FROM `vertex_tax_log`' . $where,
            $params,
            $types
        );

        return new JsonResponse(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Build the WHERE clause + bound params for a cleanup range. Cutoffs for the age presets
     * are computed server-side; only the custom range accepts client-supplied dates.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildFilter(string $range, mixed $from, mixed $to): array
    {
        $now = new \DateTimeImmutable();

        switch ($range) {
            case 'all':
                return ['', [], []];

            case '30d':
                $cutoff = $now->sub(new \DateInterval('P30D'));
                break;

            case '3m':
                $cutoff = $now->sub(new \DateInterval('P3M'));
                break;

            case '6m':
                $cutoff = $now->sub(new \DateInterval('P6M'));
                break;

            case 'custom':
                return $this->buildCustomFilter($from, $to);

            default:
                throw new InvalidArgumentException('Invalid range.');
        }

        return [
            ' WHERE `created_at` < :cutoff',
            ['cutoff' => $cutoff->format(Defaults::STORAGE_DATE_TIME_FORMAT)],
            [],
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildCustomFilter(mixed $from, mixed $to): array
    {
        $fromDate = $this->parseDate($from);
        $toDate = $this->parseDate($to);

        if ($fromDate === null || $toDate === null) {
            throw new InvalidArgumentException('A custom range requires both a start and end date.');
        }

        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('The start date must be before the end date.');
        }

        return [
            ' WHERE `created_at` BETWEEN :from AND :to',
            [
                'from' => $fromDate->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'to' => $toDate->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
            [],
        ];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Invalid date format.');
        }
    }
}
