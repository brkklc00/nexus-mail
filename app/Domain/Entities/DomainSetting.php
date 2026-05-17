<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'domain_settings')]
#[ORM\Index(columns: ['domain'], name: 'idx_domain')]
#[ORM\Index(columns: ['isActive'], name: 'idx_is_active')]
class DomainSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $domain;

    #[ORM\Column(type: 'string', length: 255)]
    private string $siteTitle;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $siteLogo = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $siteFavicon = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $siteDefaultAvatar = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $siteDescription = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getSiteTitle(): string
    {
        return $this->siteTitle;
    }

    public function setSiteTitle(string $siteTitle): self
    {
        $this->siteTitle = $siteTitle;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSiteLogo(): ?string
    {
        return $this->siteLogo;
    }

    public function setSiteLogo(?string $siteLogo): self
    {
        $this->siteLogo = $siteLogo;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSiteFavicon(): ?string
    {
        return $this->siteFavicon;
    }

    public function setSiteFavicon(?string $siteFavicon): self
    {
        $this->siteFavicon = $siteFavicon;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSiteDefaultAvatar(): ?string
    {
        return $this->siteDefaultAvatar;
    }

    public function setSiteDefaultAvatar(?string $siteDefaultAvatar): self
    {
        $this->siteDefaultAvatar = $siteDefaultAvatar;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSiteDescription(): ?string
    {
        return $this->siteDescription;
    }

    public function setSiteDescription(?string $siteDescription): self
    {
        $this->siteDescription = $siteDescription;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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
     * Array formatında döndür (eski config.php formatı ile uyumlu)
     */
    public function toArray(): array
    {
        return [
            'site_title' => $this->siteTitle,
            'site_logo' => $this->siteLogo,
            'site_favicon' => $this->siteFavicon,
            'site_default_avatar' => $this->siteDefaultAvatar,
            'site_description' => $this->siteDescription,
        ];
    }
}
