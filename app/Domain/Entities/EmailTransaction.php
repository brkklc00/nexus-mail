<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use App\Domain\Enum\EmailTransactionType;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_transactions')]
#[ORM\HasLifecycleCallbacks]
class EmailTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 50, enumType: EmailTransactionType::class)]
    private EmailTransactionType $type;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private float $amount;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, name: 'balance_before')]
    private float $balanceBefore;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2, name: 'balance_after')]
    private float $balanceAfter;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true, name: 'reference_type')]
    private ?string $referenceType = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'reference_id')]
    private ?int $referenceId = null;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function normalizeTypeForStorage(): void
    {
        $this->type = $this->type->canonical();
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

    public function getType(): EmailTransactionType
    {
        return $this->type->canonical();
    }

    public function setType(EmailTransactionType $type): self
    {
        $this->type = $type->canonical();
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getBalanceBefore(): float
    {
        return $this->balanceBefore;
    }

    public function setBalanceBefore(float $balanceBefore): self
    {
        $this->balanceBefore = $balanceBefore;
        return $this;
    }

    public function getBalanceAfter(): float
    {
        return $this->balanceAfter;
    }

    public function setBalanceAfter(float $balanceAfter): self
    {
        $this->balanceAfter = $balanceAfter;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function setReferenceType(?string $referenceType): self
    {
        $this->referenceType = $referenceType;
        return $this;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function setReferenceId(?int $referenceId): self
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }
}

