<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_data_pool_lists')]
#[ORM\HasLifecycleCallbacks]
class EmailDataPoolList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer', name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'integer', name: 'total_count')]
    private int $totalCount = 0;

    #[ORM\Column(type: 'integer', name: 'active_count')]
    private int $activeCount = 0;

    #[ORM\Column(type: 'integer', name: 'passive_count')]
    private int $passiveCount = 0;

    #[ORM\Column(type: 'datetime', name: 'updated_count_at', nullable: true)]
    private ?DateTimeInterface $updatedCountAt = null;

    /** @var Collection<int, EmailDataPool> */
    #[ORM\OneToMany(targetEntity: EmailDataPool::class, mappedBy: 'poolList')]
    private Collection $entries;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function setTotalCount(int $totalCount): self
    {
        $this->totalCount = max(0, $totalCount);
        return $this;
    }

    public function getActiveCount(): int
    {
        return $this->activeCount;
    }

    public function setActiveCount(int $activeCount): self
    {
        $this->activeCount = max(0, $activeCount);
        return $this;
    }

    public function getPassiveCount(): int
    {
        return $this->passiveCount;
    }

    public function setPassiveCount(int $passiveCount): self
    {
        $this->passiveCount = max(0, $passiveCount);
        return $this;
    }

    public function getUpdatedCountAt(): ?DateTimeInterface
    {
        return $this->updatedCountAt;
    }

    public function setUpdatedCountAt(?DateTimeInterface $updatedCountAt): self
    {
        $this->updatedCountAt = $updatedCountAt;
        return $this;
    }

    /** @return Collection<int, EmailDataPool> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }
}
