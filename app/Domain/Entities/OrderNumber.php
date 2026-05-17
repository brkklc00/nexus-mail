<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enum\OrderNumberStatus;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'order_numbers')]
class OrderNumber
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'orderNumbers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: 'string', length: 20)]
    private string $phoneE164;

    #[ORM\Column(type: 'string', length: 20, enumType: OrderNumberStatus::class)]
    private OrderNumberStatus $status = OrderNumberStatus::QUEUED;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getPhoneE164(): string
    {
        return $this->phoneE164;
    }

    public function setPhoneE164(string $phoneE164): self
    {
        $this->phoneE164 = $phoneE164;
        return $this;
    }

    public function getStatus(): OrderNumberStatus
    {
        return $this->status;
    }

    public function setStatus(OrderNumberStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function setProviderMessageId(?string $providerMessageId): self
    {
        $this->providerMessageId = $providerMessageId;
        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): self
    {
        $this->errorCode = $errorCode;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

