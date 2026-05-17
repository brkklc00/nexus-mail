<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'shortened_urls')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['short_code'])]
class ShortenedUrl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'original_url', type: 'text')]
    private string $originalUrl;

    #[ORM\Column(name: 'short_url', type: 'string', length: 255)]
    private string $shortUrl;

    #[ORM\Column(name: 'short_code', type: 'string', length: 50, unique: true)]
    private string $shortCode;

    #[ORM\Column(name: 'api_id', type: 'string', length: 50, nullable: true)]
    private ?string $apiId = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'click_count', type: 'integer', options: ['default' => 0])]
    private int $clickCount = 0;

    #[ORM\Column(name: 'click_stats', type: 'json', nullable: true)]
    private ?array $clickStats = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_clicked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastClickedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getOriginalUrl(): string
    {
        return $this->originalUrl;
    }

    public function setOriginalUrl(string $originalUrl): self
    {
        $this->originalUrl = $originalUrl;
        return $this;
    }

    public function getShortUrl(): string
    {
        return $this->shortUrl;
    }

    public function setShortUrl(string $shortUrl): self
    {
        $this->shortUrl = $shortUrl;
        return $this;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function setShortCode(string $shortCode): self
    {
        $this->shortCode = $shortCode;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function setClickCount(int $clickCount): self
    {
        $this->clickCount = $clickCount;
        return $this;
    }

    public function incrementClickCount(): self
    {
        $this->clickCount++;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastClickedAt(): ?DateTimeImmutable
    {
        return $this->lastClickedAt;
    }

    public function setLastClickedAt(?DateTimeImmutable $lastClickedAt): self
    {
        $this->lastClickedAt = $lastClickedAt;
        return $this;
    }

    public function getApiId(): ?string
    {
        return $this->apiId;
    }

    public function setApiId(?string $apiId): self
    {
        $this->apiId = $apiId;
        return $this;
    }

    public function getClickStats(): ?array
    {
        return $this->clickStats;
    }

    public function setClickStats(?array $clickStats): self
    {
        $this->clickStats = $clickStats;
        return $this;
    }
}

