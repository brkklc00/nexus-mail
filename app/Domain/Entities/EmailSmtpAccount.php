<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_smtp_accounts')]
#[ORM\HasLifecycleCallbacks]
class EmailSmtpAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255)]
    private string $host;

    #[ORM\Column(type: 'integer')]
    private int $port;

    #[ORM\Column(type: 'string', length: 255)]
    private string $username;

    #[ORM\Column(type: 'text')]
    private string $password; // Encrypted

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $encryption = null;

    #[ORM\Column(type: 'string', length: 255, name: 'from_email')]
    private string $fromEmail;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'from_name')]
    private ?string $fromName = null;

    #[ORM\Column(type: 'integer', name: 'daily_limit')]
    private int $dailyLimit = 1000;

    #[ORM\Column(type: 'integer', name: 'daily_sent')]
    private int $dailySent = 0;

    #[ORM\Column(type: 'date', nullable: true, name: 'last_reset_date')]
    private ?DateTimeInterface $lastResetDate = null;

    #[ORM\Column(type: 'integer', name: 'hourly_limit')]
    private int $hourlyLimit = 100;

    #[ORM\Column(type: 'integer', name: 'hourly_sent')]
    private int $hourlySent = 0;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'last_reset_hour')]
    private ?DateTimeInterface $lastResetHour = null;

    #[ORM\Column(type: 'integer', name: 'minute_limit')]
    private int $minuteLimit = 10;

    #[ORM\Column(type: 'integer', name: 'minute_sent')]
    private int $minuteSent = 0;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'last_reset_minute')]
    private ?DateTimeInterface $lastResetMinute = null;

    #[ORM\Column(type: 'boolean', name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer')]
    private int $priority = 1;

    #[ORM\Column(type: 'integer', name: 'total_sent')]
    private int $totalSent = 0;

    #[ORM\Column(type: 'integer', name: 'total_failed')]
    private int $totalFailed = 0;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, name: 'success_rate')]
    private float $successRate = 100.00;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'last_used_at')]
    private ?DateTimeInterface $lastUsedAt = null;

    #[ORM\Column(type: 'text', nullable: true, name: 'last_error')]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', name: 'updated_at')]
    private DateTimeInterface $updatedAt;

    public function __construct()
    {
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getEncryption(): ?string
    {
        return $this->encryption;
    }

    public function setEncryption(?string $encryption): self
    {
        $this->encryption = $encryption;
        return $this;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $fromEmail): self
    {
        $this->fromEmail = $fromEmail;
        return $this;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): self
    {
        $this->fromName = $fromName;
        return $this;
    }

    public function getDailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function setDailyLimit(int $dailyLimit): self
    {
        $this->dailyLimit = $dailyLimit;
        return $this;
    }

    public function getDailySent(): int
    {
        return $this->dailySent;
    }

    public function setDailySent(int $dailySent): self
    {
        $this->dailySent = $dailySent;
        return $this;
    }

    public function incrementDailySent(): self
    {
        $this->dailySent++;
        return $this;
    }

    public function resetDailySent(): self
    {
        $this->dailySent = 0;
        $this->lastResetDate = new DateTime();
        return $this;
    }

    public function getLastResetDate(): ?DateTimeInterface
    {
        return $this->lastResetDate;
    }

    public function setLastResetDate(?DateTimeInterface $lastResetDate): self
    {
        $this->lastResetDate = $lastResetDate;
        return $this;
    }

    public function getHourlyLimit(): int
    {
        return $this->hourlyLimit;
    }

    public function setHourlyLimit(int $hourlyLimit): self
    {
        $this->hourlyLimit = $hourlyLimit;
        return $this;
    }

    public function getHourlySent(): int
    {
        return $this->hourlySent;
    }

    public function setHourlySent(int $hourlySent): self
    {
        $this->hourlySent = $hourlySent;
        return $this;
    }

    public function incrementHourlySent(): self
    {
        $this->hourlySent++;
        return $this;
    }

    public function resetHourlySent(): self
    {
        $this->hourlySent = 0;
        $this->lastResetHour = new DateTime();
        return $this;
    }

    public function getLastResetHour(): ?DateTimeInterface
    {
        return $this->lastResetHour;
    }

    public function setLastResetHour(?DateTimeInterface $lastResetHour): self
    {
        $this->lastResetHour = $lastResetHour;
        return $this;
    }

    public function isHourlyLimitReached(): bool
    {
        return $this->hourlySent >= $this->hourlyLimit;
    }

    public function shouldResetHour(): bool
    {
        if (!$this->lastResetHour) {
            return true;
        }

        $now = new DateTime();
        $lastHour = (int)$this->lastResetHour->format('YmdH');
        $currentHour = (int)$now->format('YmdH');
        
        return $lastHour !== $currentHour;
    }

    public function getMinuteLimit(): int
    {
        return $this->minuteLimit;
    }

    public function setMinuteLimit(int $minuteLimit): self
    {
        $this->minuteLimit = $minuteLimit;
        return $this;
    }

    public function getMinuteSent(): int
    {
        return $this->minuteSent;
    }

    public function setMinuteSent(int $minuteSent): self
    {
        $this->minuteSent = $minuteSent;
        return $this;
    }

    public function incrementMinuteSent(): self
    {
        $this->minuteSent++;
        return $this;
    }

    public function resetMinuteSent(): self
    {
        $this->minuteSent = 0;
        $this->lastResetMinute = new DateTime();
        return $this;
    }

    public function getLastResetMinute(): ?DateTimeInterface
    {
        return $this->lastResetMinute;
    }

    public function setLastResetMinute(?DateTimeInterface $lastResetMinute): self
    {
        $this->lastResetMinute = $lastResetMinute;
        return $this;
    }

    public function isMinuteLimitReached(): bool
    {
        return $this->minuteSent >= $this->minuteLimit;
    }

    public function shouldResetMinute(): bool
    {
        if (!$this->lastResetMinute) {
            return true;
        }

        $now = new DateTime();
        $lastMinute = (int)$this->lastResetMinute->format('YmdHi');
        $currentMinute = (int)$now->format('YmdHi');
        
        return $lastMinute !== $currentMinute;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getTotalSent(): int
    {
        return $this->totalSent;
    }

    public function setTotalSent(int $totalSent): self
    {
        $this->totalSent = $totalSent;
        return $this;
    }

    public function incrementTotalSent(): self
    {
        $this->totalSent++;
        return $this;
    }

    public function getTotalFailed(): int
    {
        return $this->totalFailed;
    }

    public function setTotalFailed(int $totalFailed): self
    {
        $this->totalFailed = $totalFailed;
        return $this;
    }

    public function incrementTotalFailed(): self
    {
        $this->totalFailed++;
        return $this;
    }

    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    public function setSuccessRate(float $successRate): self
    {
        $this->successRate = $successRate;
        return $this;
    }

    public function calculateSuccessRate(): self
    {
        $total = $this->totalSent + $this->totalFailed;
        if ($total > 0) {
            $this->successRate = ($this->totalSent / $total) * 100;
        }
        return $this;
    }

    public function getLastUsedAt(): ?DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeInterface $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;
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

    /**
     * Günlük limit doldu mu?
     */
    public function isDailyLimitReached(): bool
    {
        return $this->dailySent >= $this->dailyLimit;
    }

    /**
     * Herhangi bir limit doldu mu? (Günlük, saatlik veya dakikalık)
     */
    public function isAnyLimitReached(): bool
    {
        return $this->isDailyLimitReached() || 
               $this->isHourlyLimitReached() || 
               $this->isMinuteLimitReached();
    }

    /**
     * Bugün sıfırlanmalı mı?
     */
    public function shouldResetToday(): bool
    {
        if (!$this->lastResetDate) {
            return true;
        }

        $today = new DateTime();
        return $this->lastResetDate->format('Y-m-d') !== $today->format('Y-m-d');
    }

    /**
     * Kullanım durumu yüzdesi
     */
    public function getUsagePercentage(): float
    {
        if ($this->dailyLimit === 0) {
            return 0;
        }
        return ($this->dailySent / $this->dailyLimit) * 100;
    }
}

