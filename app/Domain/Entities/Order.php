<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enum\OrderStatus;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: 'string', length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::PENDING;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $requestedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $processedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sentCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $failedCount = 0;

    #[ORM\Column(type: 'text')]
    private string $messageText;

    #[ORM\Column(type: 'integer', options: ['default' => 100])]
    private int $deliveryPercentage = 100;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, PhoneBook>
     */
    #[ORM\ManyToMany(targetEntity: PhoneBook::class, inversedBy: 'orders')]
    #[ORM\JoinTable(name: 'order_phone_books')]
    private Collection $phoneBooks;

    /**
     * @var Collection<int, OrderNumber>
     */
    #[ORM\OneToMany(targetEntity: OrderNumber::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $orderNumbers;

    public function __construct()
    {
        $this->phoneBooks = new ArrayCollection();
        $this->orderNumbers = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getRequestedCount(): int
    {
        return $this->requestedCount;
    }

    public function setRequestedCount(int $requestedCount): self
    {
        $this->requestedCount = $requestedCount;
        return $this;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function setProcessedCount(int $processedCount): self
    {
        $this->processedCount = $processedCount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function incrementProcessedCount(int $amount = 1): self
    {
        $this->processedCount += $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function setSentCount(int $sentCount): self
    {
        $this->sentCount = $sentCount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function incrementSentCount(int $amount = 1): self
    {
        $this->sentCount += $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function setFailedCount(int $failedCount): self
    {
        $this->failedCount = $failedCount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function incrementFailedCount(int $amount = 1): self
    {
        $this->failedCount += $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function setMessageText(string $messageText): self
    {
        $this->messageText = $messageText;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getDeliveryPercentage(): int
    {
        return $this->deliveryPercentage;
    }

    public function setDeliveryPercentage(int $deliveryPercentage): self
    {
        if ($deliveryPercentage < 0 || $deliveryPercentage > 100) {
            throw new \InvalidArgumentException('Delivery percentage must be between 0 and 100');
        }
        $this->deliveryPercentage = $deliveryPercentage;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, PhoneBook>
     */
    public function getPhoneBooks(): Collection
    {
        return $this->phoneBooks;
    }

    public function addPhoneBook(PhoneBook $phoneBook): self
    {
        if (!$this->phoneBooks->contains($phoneBook)) {
            $this->phoneBooks->add($phoneBook);
        }
        return $this;
    }

    /**
     * @return Collection<int, OrderNumber>
     */
    public function getOrderNumbers(): Collection
    {
        return $this->orderNumbers;
    }

    public function addOrderNumber(OrderNumber $orderNumber): self
    {
        if (!$this->orderNumbers->contains($orderNumber)) {
            $this->orderNumbers->add($orderNumber);
            $orderNumber->setOrder($this);
        }
        return $this;
    }
}

