<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'email_smtp_daily_reports',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_smtp_daily_report', columns: ['source', 'report_date', 'domain', 'smtp_name'])],
    indexes: [
        new ORM\Index(name: 'idx_smtp_daily_report_date', columns: ['report_date']),
        new ORM\Index(name: 'idx_smtp_daily_report_source', columns: ['source']),
    ]
)]
#[ORM\HasLifecycleCallbacks]
class EmailSmtpDailyReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 40)]
    private string $source = 'alibaba_directmail';

    #[ORM\Column(type: 'date', name: 'report_date')]
    private DateTimeInterface $reportDate;

    #[ORM\Column(type: 'string', length: 191, name: 'smtp_name')]
    private string $smtpName = '';

    #[ORM\Column(type: 'string', length: 191)]
    private string $domain = '';

    #[ORM\Column(type: 'integer')]
    private int $total = 0;

    #[ORM\Column(type: 'integer')]
    private int $successful = 0;

    #[ORM\Column(type: 'integer')]
    private int $failed = 0;

    #[ORM\Column(type: 'integer', name: 'invalid_address')]
    private int $invalidAddress = 0;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, name: 'success_rate')]
    private float $successRate = 0.0;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, name: 'invalid_rate')]
    private float $invalidRate = 0.0;

    #[ORM\Column(type: 'text', nullable: true, name: 'raw_payload')]
    private ?string $rawPayload = null;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', name: 'updated_at')]
    private DateTimeInterface $updatedAt;

    public function __construct()
    {
        $now = new DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->reportDate = new DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getReportDate(): DateTimeInterface
    {
        return $this->reportDate;
    }

    public function setReportDate(DateTimeInterface $reportDate): self
    {
        $this->reportDate = $reportDate;
        return $this;
    }

    public function getSmtpName(): string
    {
        return $this->smtpName;
    }

    public function setSmtpName(string $smtpName): self
    {
        $this->smtpName = $smtpName;
        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = strtolower(trim($domain));
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = max(0, $total);
        return $this;
    }

    public function getSuccessful(): int
    {
        return $this->successful;
    }

    public function setSuccessful(int $successful): self
    {
        $this->successful = max(0, $successful);
        return $this;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): self
    {
        $this->failed = max(0, $failed);
        return $this;
    }

    public function getInvalidAddress(): int
    {
        return $this->invalidAddress;
    }

    public function setInvalidAddress(int $invalidAddress): self
    {
        $this->invalidAddress = max(0, $invalidAddress);
        return $this;
    }

    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    public function setSuccessRate(float $successRate): self
    {
        $this->successRate = max(0.0, min(100.0, $successRate));
        return $this;
    }

    public function getInvalidRate(): float
    {
        return $this->invalidRate;
    }

    public function setInvalidRate(float $invalidRate): self
    {
        $this->invalidRate = max(0.0, min(100.0, $invalidRate));
        return $this;
    }

    public function getRawPayload(): ?string
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?string $rawPayload): self
    {
        $this->rawPayload = $rawPayload;
        return $this;
    }
}
