<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enum\EmailOrderStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_orders')]
#[ORM\HasLifecycleCallbacks]
class EmailOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: EmailSmtpAccount::class)]
    #[ORM\JoinColumn(name: 'smtp_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EmailSmtpAccount $smtpAccount = null;

    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EmailTemplate $template = null;

    #[ORM\Column(type: 'string', length: 500)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 50, enumType: EmailOrderStatus::class)]
    private EmailOrderStatus $status;

    #[ORM\Column(type: 'integer')]
    private int $total = 0;

    #[ORM\Column(type: 'integer')]
    private int $sent = 0;

    #[ORM\Column(type: 'integer')]
    private int $delivered = 0;

    #[ORM\Column(type: 'integer')]
    private int $bounced = 0;

    #[ORM\Column(type: 'integer')]
    private int $failed = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float $cost = 0.00;

    #[ORM\Column(type: 'integer', name: 'delivery_percentage')]
    private int $deliveryPercentage = 100;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'target_campaign_id')]
    private ?string $targetCampaignId = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, name: 'source_type')]
    private ?string $sourceType = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'last_pool_id')]
    private ?int $lastPoolId = null;

    #[ORM\ManyToOne(targetEntity: EmailDataPoolList::class)]
    #[ORM\JoinColumn(name: 'pool_list_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EmailDataPoolList $poolList = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'locked_at')]
    private ?DateTimeInterface $lockedAt = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true, name: 'locked_by')]
    private ?string $lockedBy = null;

    #[ORM\Column(type: 'integer', name: 'attempt_count')]
    private int $attemptCount = 0;

    #[ORM\Column(type: 'boolean', name: 'worker_paused')]
    private bool $workerPaused = false;

    #[ORM\Column(type: 'boolean', name: 'worker_stop_requested')]
    private bool $workerStopRequested = false;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: EmailOrderEmail::class, cascade: ['persist', 'remove'])]
    private Collection $emails;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', name: 'updated_at')]
    private DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'completed_at')]
    private ?DateTimeInterface $completedAt = null;

    public function __construct()
    {
        $this->emails = new ArrayCollection();
        $this->status = EmailOrderStatus::PENDING;
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime();
    }

    // Getters and Setters

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

    public function getSmtpAccount(): ?EmailSmtpAccount
    {
        return $this->smtpAccount;
    }

    public function setSmtpAccount(?EmailSmtpAccount $smtpAccount): self
    {
        $this->smtpAccount = $smtpAccount;
        return $this;
    }

    public function getTemplate(): ?EmailTemplate
    {
        return $this->template;
    }

    public function setTemplate(?EmailTemplate $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getStatus(): EmailOrderStatus
    {
        return $this->status;
    }

    public function setStatus(EmailOrderStatus $status): self
    {
        $this->status = $status;
        
        if (
            $status === EmailOrderStatus::SENT
            || $status === EmailOrderStatus::COMPLETED
            || $status === EmailOrderStatus::FAILED
        ) {
            $this->completedAt = new DateTime();
        }
        
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;
        return $this;
    }

    public function getSent(): int
    {
        return $this->sent;
    }

    public function setSent(int $sent): self
    {
        $this->sent = $sent;
        return $this;
    }

    public function incrementSent(): self
    {
        $this->sent++;
        return $this;
    }

    public function getDelivered(): int
    {
        return $this->delivered;
    }

    public function setDelivered(int $delivered): self
    {
        $this->delivered = $delivered;
        return $this;
    }

    public function incrementDelivered(): self
    {
        $this->delivered++;
        return $this;
    }

    public function getBounced(): int
    {
        return $this->bounced;
    }

    public function setBounced(int $bounced): self
    {
        $this->bounced = $bounced;
        return $this;
    }

    public function incrementBounced(): self
    {
        $this->bounced++;
        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): self
    {
        $this->failed = $failed;
        return $this;
    }

    public function incrementFailed(): self
    {
        $this->failed++;
        return $this;
    }

    public function getCost(): float
    {
        return $this->cost;
    }

    public function setCost(float $cost): self
    {
        $this->cost = $cost;
        return $this;
    }

    public function getDeliveryPercentage(): int
    {
        return $this->deliveryPercentage;
    }

    public function setDeliveryPercentage(int $deliveryPercentage): self
    {
        $this->deliveryPercentage = $deliveryPercentage;
        return $this;
    }

    public function getTargetCampaignId(): ?string
    {
        return $this->targetCampaignId;
    }

    public function setTargetCampaignId(?string $targetCampaignId): self
    {
        $this->targetCampaignId = $targetCampaignId;
        return $this;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceType(?string $sourceType): self
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    public function getLastPoolId(): ?int
    {
        return $this->lastPoolId;
    }

    public function setLastPoolId(?int $lastPoolId): self
    {
        $this->lastPoolId = $lastPoolId;
        return $this;
    }

    public function getPoolList(): ?EmailDataPoolList
    {
        return $this->poolList;
    }

    public function setPoolList(?EmailDataPoolList $poolList): self
    {
        $this->poolList = $poolList;
        return $this;
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

    public function incrementAttemptCount(): self
    {
        $this->attemptCount++;
        return $this;
    }

    public function isWorkerPaused(): bool
    {
        return $this->workerPaused;
    }

    public function setWorkerPaused(bool $workerPaused): self
    {
        $this->workerPaused = $workerPaused;
        return $this;
    }

    public function isWorkerStopRequested(): bool
    {
        return $this->workerStopRequested;
    }

    public function setWorkerStopRequested(bool $workerStopRequested): self
    {
        $this->workerStopRequested = $workerStopRequested;
        return $this;
    }

    public function isPoolOrder(): bool
    {
        return $this->sourceType === 'pool';
    }

    public function getEmails(): Collection
    {
        return $this->emails;
    }

    public function addEmail(EmailOrderEmail $email): self
    {
        if (!$this->emails->contains($email)) {
            $this->emails->add($email);
            $email->setOrder($this);
        }
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    /**
     * Başarı oranı hesapla
     */
    public function getSuccessRate(): float
    {
        if ($this->total === 0) {
            return 0;
        }
        return ($this->delivered / $this->total) * 100;
    }

    /**
     * Gönderim tamamlandı mı?
     */
    public function isCompleted(): bool
    {
        return $this->status === EmailOrderStatus::SENT || $this->status === EmailOrderStatus::FAILED;
    }
}

