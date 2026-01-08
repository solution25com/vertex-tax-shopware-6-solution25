<?php

declare(strict_types=1);

namespace VertexTax\Core\Content\TaxLog;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Checkout\Order\OrderEntity;

class TaxLogEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerName = null;
    protected ?string $remoteIp = null;
    protected ?string $customerEmail = null;
    protected ?string $requestKey = null;
    protected ?string $type = null;
    protected ?string $orderNumber = null;
    protected ?string $orderId = null;
    protected ?string $request = null;
    protected ?string $response = null;
    protected OrderEntity $order;

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): void
    {
        $this->customerName = $customerName;
    }

    public function getRemoteIp(): ?string
    {
        return $this->remoteIp;
    }

    public function setRemoteIp(?string $remoteIp): void
    {
        $this->remoteIp = $remoteIp;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function setCustomerEmail(?string $customerEmail): void
    {
        $this->customerEmail = $customerEmail;
    }

    public function getRequestKey(): ?string
    {
        return $this->requestKey;
    }

    public function setRequestKey(?string $requestKey): void
    {
        $this->requestKey = $requestKey;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function setOrder(OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(?string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getRequest(): ?string
    {
        return $this->request;
    }

    public function setRequest(?string $request): void
    {
        $this->request = $request;
    }

    public function getResponse(): ?string
    {
        return $this->response;
    }

    public function setResponse(?string $response): void
    {
        $this->response = $response;
    }
}
