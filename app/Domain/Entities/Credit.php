<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'credits')]
class Credit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'credit')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: true)]
    private User $user;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $balance = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->user->getId();
    }

    public function setUserId(int $userId): self
    {
        // Bu metod sadece geriye uyumluluk için - user set edilmeli
        return $this;
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

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function setBalance(int $balance): self
    {
        $this->balance = $balance;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function add(int $amount): self
    {
        $this->balance += $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function deduct(int $amount): self
    {
        $this->balance -= $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function hasEnough(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

