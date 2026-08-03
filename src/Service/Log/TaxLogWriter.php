<?php

declare(strict_types=1);

namespace VertexTax\Service\Log;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Central writer for vertex_tax_log entries.
 *
 * The cart is recalculated many times during checkout, so the same request would otherwise
 * produce many near-identical log rows. Before inserting, this writer looks for an existing
 * row with the same type and request fingerprint within a short window; if found it bumps
 * occurrence_count and last_occurred_at instead of creating a duplicate.
 */
class TaxLogWriter
{
    /**
     * Deduplication window in minutes. Two identical requests within this window collapse
     * into a single row.
     */
    private const DEDUP_WINDOW_MINUTES = 5;

    private EntityRepository $taxLogRepository;

    private LoggerInterface $logger;

    public function __construct(EntityRepository $taxLogRepository, LoggerInterface $logger)
    {
        $this->taxLogRepository = $taxLogRepository;
        $this->logger = $logger;
    }

    /**
     * Persist a tax log entry, deduplicating against a recent identical entry.
     *
     * @param array<string, mixed> $logData Log payload; the same shape both call sites already build.
     */
    public function write(array $logData, Context $context): void
    {
        $now = new \DateTimeImmutable();
        $hash = hash('sha256', (string) ($logData['requestKey'] ?? ''));
        $logData['requestHash'] = $hash;

        try {
            $existing = $this->findRecentDuplicate((string) ($logData['type'] ?? ''), $hash, $now, $context);

            if ($existing !== null) {
                $this->taxLogRepository->update([[
                    'id' => $existing['id'],
                    'occurrenceCount' => ((int) $existing['occurrenceCount']) + 1,
                    'lastOccurredAt' => $now,
                ]], $context);

                return;
            }

            $logData['occurrenceCount'] = 1;
            $logData['lastOccurredAt'] = $now;
            $this->taxLogRepository->create([$logData], $context);
        } catch (\Exception $e) {
            $this->logger->error('Vertex: Failed to write tax log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{id: string, occurrenceCount: int}|null
     */
    private function findRecentDuplicate(string $type, string $hash, \DateTimeImmutable $now, Context $context): ?array
    {
        $since = $now->sub(new \DateInterval('PT' . self::DEDUP_WINDOW_MINUTES . 'M'))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('type', $type));
        $criteria->addFilter(new EqualsFilter('requestHash', $hash));
        $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $since]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        /** @var \VertexTax\Core\Content\TaxLog\TaxLogEntity|null $match */
        $match = $this->taxLogRepository->search($criteria, $context)->first();

        if ($match === null) {
            return null;
        }

        return [
            'id' => $match->getId(),
            'occurrenceCount' => (int) ($match->getOccurrenceCount() ?? 1),
        ];
    }
}
