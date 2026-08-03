<?php

declare(strict_types=1);

namespace VertexTax\Service\Order;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class DeliveryStateTransitionService
{
    private const DELIVERY_STATE_FIELD = 'stateId';

    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    public function ship(string $deliveryId, Context $context): void
    {
        try {
            $this->stateMachineRegistry->transition(
                new Transition(
                    OrderDeliveryDefinition::ENTITY_NAME,
                    $deliveryId,
                    'ship',
                    self::DELIVERY_STATE_FIELD
                ),
                $context
            );
        } catch (IllegalTransitionException | StateMachineException $exception) {
            $this->logger->error('Vertex: Failed to transition delivery to shipped', [
                'deliveryId' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
