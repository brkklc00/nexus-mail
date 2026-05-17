<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'transactional_emails')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
class TransactionalEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 255, name: 'to_email')]
    private string $toEmail;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'to_name')]
    private ?string $toName = null;

    #[ORM\Column(type: 'string', length: 500)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'from_email')]
    private ?string $fromEmail = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'from_name')]
    private ?string $fromName = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = 'pending'; // pending, sent, failed

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'message_id')]
    private ?string $messageId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToOne(targetEntity: EmailSmtpAccount::class)]
    #[ORM\JoinColumn(name: 'smtp_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EmailSmtpAccount $smtpAccount = null;

    #[ORM\Column(type: 'datetime', nullable: true, name: 'sent_at')]
    private ?DateTimeInterface $sentAt = null;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getToEmail(): string
    {
        return $this->toEmail;
    }

    public function setToEmail(string $toEmail): self
    {
        $this->toEmail = $toEmail;
        return $this;
    }

    public function getToName(): ?string
    {
        return $this->toName;
    }

    public function setToName(?string $toName): self
    {
        $this->toName = $toName;
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

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(?string $fromEmail): self
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        
        if ($status === 'sent' && $this->sentAt === null) {
            $this->sentAt = new DateTime();
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

    public function getSmtpAccount(): ?EmailSmtpAccount
    {
        return $this->smtpAccount;
    }

    public function setSmtpAccount(?EmailSmtpAccount $smtpAccount): self
    {
        $this->smtpAccount = $smtpAccount;
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

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Status badge class
     */
    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            'pending' => 'badge-warning',
            'sent' => 'badge-success',
            'failed' => 'badge-danger',
            default => 'badge-secondary'
        };
    }

    /**
     * Status label
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Bekliyor',
            'sent' => 'Gönderildi',
            'failed' => 'Başarısız',
            default => 'Bilinmiyor'
        };
    }
}

