<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enum\EmailStatus;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_order_emails')]
class EmailOrderEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EmailOrder::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EmailOrder $order;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 50, enumType: EmailStatus::class)]
    private EmailStatus $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'message_id')]
    private ?string $messageId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'sent_at')]
    private ?DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'delivered_at')]
    private ?DateTimeInterface $deliveredAt = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'locked_at')]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true, name: 'locked_by')]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'integer', name: 'attempt_count')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'string', length: 64, nullable: true, name: 'last_error_code')]
    private ?string $lastErrorCode = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, name: 'last_error_category')]
    private ?string $lastErrorCategory = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'failed_at')]
    private ?DateTimeInterface $failedAt = null;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->status = EmailStatus::PENDING;
        $this->createdAt = new DateTime();
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): EmailOrder
    {
        return $this->order;
    }

    public function setOrder(EmailOrder $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): EmailStatus
    {
        return $this->status;
    }

    public function setStatus(EmailStatus $status): self
    {
        $this->status = $status;
        
        // Auto-set sentAt when status becomes SENT
        if ($status === EmailStatus::SENT && $this->sentAt === null) {
            $this->sentAt = new DateTime();
        }
        
        // Auto-set deliveredAt when status becomes DELIVERED
        if ($status === EmailStatus::DELIVERED && $this->deliveredAt === null) {
            $this->deliveredAt = new DateTime();
        }
        
        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function getSentAt(): ?DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeInterface $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getDeliveredAt(): ?DateTimeInterface
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?DateTimeInterface $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getLockedAt(): ?DateTimeInterface
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?DateTimeInterface $lockedAt): self
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function setLockedBy(?string $lockedBy): self
    {
        $this->lockedBy = $lockedBy;
        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): self
    {
        $this->attemptCount = $attemptCount;
        return $this;
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function setLastErrorCode(?string $lastErrorCode): self
    {
        $this->lastErrorCode = $lastErrorCode;
        return $this;
    }

    public function getLastErrorCategory(): ?string
    {
        return $this->lastErrorCategory;
    }

    public function setLastErrorCategory(?string $lastErrorCategory): self
    {
        $this->lastErrorCategory = $lastErrorCategory;
        return $this;
    }

    public function getFailedAt(): ?DateTimeInterface
    {
        return $this->failedAt;
    }

    public function setFailedAt(?DateTimeInterface $failedAt): self
    {
        $this->failedAt = $failedAt;
        return $this;
    }
}

