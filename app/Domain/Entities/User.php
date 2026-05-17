<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $username;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $email;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $password;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'tr'])]
    private string $locale = 'tr';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $twoFactorSecret = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $twoFactorRecoveryCodes = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 100])]
    private int $smsDeliveryPercentage = 100;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $refundEnabled = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'otp_balance')]
    private int $otpBalance = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'sms_transactional_balance')]
    private int $smsTransactionalBalance = 0;

    #[ORM\Column(type: 'string', length: 64, nullable: true, unique: true, name: 'api_key')]
    private ?string $apiKey = null;

    /** Toplu SMS sipariş API (POST /api/orders/bulk) — admin açar, varsayılan kapalı */
    #[ORM\Column(type: 'boolean', options: ['default' => false], name: 'bulk_sms_api_enabled')]
    private bool $bulkSmsApiEnabled = false;

    /** cepsms|voiceguru|caddesms|kuzeygrup|yxinternet|uipapp|hizlismsgonder — null ise panel .env SMS_PROVIDER (yoksa uipapp) */
    #[ORM\Column(type: 'string', length: 32, nullable: true, name: 'sms_provider')]
    private ?string $smsProvider = null;

    private const SMS_PROVIDER_CODES = [
        'cepsms',
        'voiceguru',
        'caddesms',
        'kuzeygrup',
        'yxinternet',
        'uipapp',
        'hizlismsgonder',
    ];

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'], name: 'email_credit')]
    private float $emailCredit = 0.00;

    #[ORM\Column(type: 'integer', options: ['default' => 100], name: 'email_delivery_percentage')]
    private int $emailDeliveryPercentage = 100;

    #[ORM\Column(type: 'boolean', options: ['default' => true], name: 'email_refund_enabled')]
    private bool $emailRefundEnabled = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'email_otp_balance')]
    private int $emailOtpBalance = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'email_transactional_balance')]
    private int $emailTransactionalBalance = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false], name: 'tib_enabled')]
    private bool $tibEnabled = false;

    #[ORM\Column(type: 'integer', options: ['default' => 2], name: 'tib_max_lists')]
    private int $tibMaxLists = 2;

    #[ORM\Column(type: 'integer', options: ['default' => 30], name: 'tib_max_domains_per_list')]
    private int $tibMaxDomainsPerList = 30;

    #[ORM\Column(type: 'datetime_immutable', nullable: true, name: 'tib_expires_at')]
    private ?DateTimeImmutable $tibExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false], name: 'whatsapp_enabled')]
    private bool $whatsappEnabled = false;

    #[ORM\Column(type: 'integer', options: ['default' => 1], name: 'whatsapp_max_accounts')]
    private int $whatsappMaxAccounts = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'whatsapp_message_balance')]
    private int $whatsappMessageBalance = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'whatsapp_otp_balance')]
    private int $whatsappOtpBalance = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true, name: 'whatsapp_expires_at')]
    private ?DateTimeImmutable $whatsappExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false], name: 'telegram_enabled')]
    private bool $telegramEnabled = false;

    #[ORM\Column(type: 'integer', options: ['default' => 1], name: 'telegram_max_bots')]
    private int $telegramMaxBots = 1;

    #[ORM\Column(type: 'datetime_immutable', nullable: true, name: 'telegram_expires_at')]
    private ?DateTimeImmutable $telegramExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true], name: 'url_shortener_enabled')]
    private bool $urlShortenerEnabled = true;

    #[ORM\Column(type: 'integer', nullable: true, name: 'url_shortener_max_urls')]
    private ?int $urlShortenerMaxUrls = null;

    #[ORM\Column(type: 'json', nullable: true, name: 'url_shortener_allowed_domains')]
    private ?array $urlShortenerAllowedDomains = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true], name: 'telegram_commands_enabled')]
    private bool $telegramCommandsEnabled = true;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'], name: 'smm_balance')]
    private float $smmBalance = 0.00;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * İzinli Sayfalar - Kullanıcının erişebileceği sayfalar (JSON array)
     * Null ise tüm sayfalara erişebilir (role bazlı kontrol)
     * Boş array ise hiçbir sayfaya erişemez
     * Dolu array ise sadece listelenen sayfalara erişebilir
     * Örnek: ["/orders", "/phone-books", "/blacklist"]
     */
    #[ORM\Column(type: 'json', nullable: true, name: 'allowed_pages')]
    private ?array $allowedPages = null;

    /**
     * @var Collection<int, Role>
     */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_roles')]
    private Collection $roles;

    /**
     * @var Collection<int, PhoneBook>
     */
    #[ORM\OneToMany(targetEntity: PhoneBook::class, mappedBy: 'user')]
    private Collection $phoneBooks;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'user')]
    private Collection $orders;

    #[ORM\OneToOne(targetEntity: Credit::class, mappedBy: 'user')]
    private ?Credit $credit = null;

    /**
     * Hesap Yöneticisi (Parent User) - Bu kullanıcının altındaki hesapları yöneten kullanıcı
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'childUsers')]
    #[ORM\JoinColumn(name: 'parent_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $parentUser = null;

    /**
     * Alt Hesaplar (Child Users) - Bu kullanıcının yönettiği personel hesapları
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'parentUser')]
    private Collection $childUsers;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
        $this->phoneBooks = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->childUsers = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
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
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
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

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSecret;
    }

    public function setTwoFactorSecret(?string $twoFactorSecret): self
    {
        $this->twoFactorSecret = $twoFactorSecret;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getTwoFactorRecoveryCodes(): ?array
    {
        return $this->twoFactorRecoveryCodes;
    }

    public function setTwoFactorRecoveryCodes(?array $twoFactorRecoveryCodes): self
    {
        $this->twoFactorRecoveryCodes = $twoFactorRecoveryCodes;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function has2FA(): bool
    {
        return $this->twoFactorSecret !== null;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;
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
     * @return Collection<int, Role>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRole(Role $role): self
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }
        return $this;
    }

    public function removeRole(Role $role): self
    {
        $this->roles->removeElement($role);
        return $this;
    }

    public function hasPermission(string $permission, string $action = 'read'): bool
    {
        foreach ($this->roles as $role) {
            if ($role->hasPermission($permission, $action)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return Collection<int, PhoneBook>
     */
    public function getPhoneBooks(): Collection
    {
        return $this->phoneBooks;
    }

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function getCredit(): ?Credit
    {
        return $this->credit;
    }

    public function setCredit(?Credit $credit): self
    {
        $this->credit = $credit;
        return $this;
    }

    public function getSmsDeliveryPercentage(): int
    {
        return $this->smsDeliveryPercentage;
    }

    public function setSmsDeliveryPercentage(int $smsDeliveryPercentage): self
    {
        $this->smsDeliveryPercentage = max(0, min(100, $smsDeliveryPercentage));
        return $this;
    }

    public function isRefundEnabled(): bool
    {
        return $this->refundEnabled;
    }

    public function setRefundEnabled(bool $refundEnabled): self
    {
        $this->refundEnabled = $refundEnabled;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getOtpBalance(): int
    {
        return $this->otpBalance;
    }

    public function setOtpBalance(int $otpBalance): self
    {
        $this->otpBalance = max(0, $otpBalance);
        return $this;
    }

    public function addOtpBalance(int $amount): self
    {
        $this->otpBalance += $amount;
        return $this;
    }

    public function deductOtpBalance(int $amount): self
    {
        $this->otpBalance = max(0, $this->otpBalance - $amount);
        return $this;
    }

    // SMS Transactional Balance Methods
    
    public function getSmsTransactionalBalance(): int
    {
        return $this->smsTransactionalBalance ?? 0;
    }

    public function setSmsTransactionalBalance(int $smsTransactionalBalance): self
    {
        $this->smsTransactionalBalance = $smsTransactionalBalance;
        return $this;
    }

    public function addSmsTransactionalBalance(int $amount): self
    {
        $this->smsTransactionalBalance = ($this->smsTransactionalBalance ?? 0) + $amount;
        return $this;
    }

    public function deductSmsTransactionalBalance(int $amount): self
    {
        $this->smsTransactionalBalance = max(0, ($this->smsTransactionalBalance ?? 0) - $amount);
        return $this;
    }

    public function hasOtpBalance(int $amount = 1): bool
    {
        return $this->otpBalance >= $amount;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function generateApiKey(): self
    {
        $this->apiKey = bin2hex(random_bytes(32)); // 64 karakter
        return $this;
    }

    public function revokeApiKey(): self
    {
        $this->apiKey = null;
        return $this;
    }

    public function isBulkSmsApiEnabled(): bool
    {
        return $this->bulkSmsApiEnabled;
    }

    public function setBulkSmsApiEnabled(bool $bulkSmsApiEnabled): self
    {
        $this->bulkSmsApiEnabled = $bulkSmsApiEnabled;
        return $this;
    }

    public function getSmsProvider(): ?string
    {
        return $this->smsProvider;
    }

    public function setSmsProvider(?string $smsProvider): self
    {
        $this->smsProvider = $smsProvider;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Form / API girdisi: boş veya geçersiz ise null (sistem varsayılanı).
     */
    public static function sanitizeSmsProviderInput(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $t = strtolower(trim($raw));
        if ($t === '' || $t === '__default__' || $t === 'default') {
            return null;
        }

        return in_array($t, self::SMS_PROVIDER_CODES, true) ? $t : null;
    }

    /**
     * Worker ve gönderim API’leri için nihai sağlayıcı kodu (küçük harf).
     */
    public function getResolvedSmsProvider(): string
    {
        $own = $this->smsProvider !== null ? strtolower(trim($this->smsProvider)) : null;
        if ($own !== null && $own !== '' && in_array($own, self::SMS_PROVIDER_CODES, true)) {
            return $own;
        }

        $env = $_ENV['SMS_PROVIDER'] ?? getenv('SMS_PROVIDER');
        $env = is_string($env) ? strtolower(trim($env)) : '';
        if ($env !== '' && in_array($env, self::SMS_PROVIDER_CODES, true)) {
            return $env;
        }

        return 'uipapp';
    }

    // Email Credit Methods
    
    public function getEmailCredit(): float
    {
        return $this->emailCredit ?? 0.00;
    }

    public function setEmailCredit(float $emailCredit): self
    {
        $this->emailCredit = $emailCredit;
        return $this;
    }

    public function addEmailCredit(float $amount): self
    {
        $this->emailCredit = ($this->emailCredit ?? 0.00) + $amount;
        return $this;
    }

    public function deductEmailCredit(float $amount): self
    {
        $this->emailCredit = max(0, ($this->emailCredit ?? 0.00) - $amount);
        return $this;
    }

    public function getEmailDeliveryPercentage(): int
    {
        return $this->emailDeliveryPercentage ?? 100;
    }

    public function setEmailDeliveryPercentage(int $emailDeliveryPercentage): self
    {
        $this->emailDeliveryPercentage = $emailDeliveryPercentage;
        return $this;
    }

    public function isEmailRefundEnabled(): bool
    {
        return $this->emailRefundEnabled ?? true;
    }

    public function setEmailRefundEnabled(bool $emailRefundEnabled): self
    {
        $this->emailRefundEnabled = $emailRefundEnabled;
        return $this;
    }

    // Email OTP Balance Methods
    
    public function getEmailOtpBalance(): int
    {
        return $this->emailOtpBalance ?? 0;
    }

    public function setEmailOtpBalance(int $emailOtpBalance): self
    {
        $this->emailOtpBalance = $emailOtpBalance;
        return $this;
    }

    public function addEmailOtpBalance(int $amount): self
    {
        $this->emailOtpBalance = ($this->emailOtpBalance ?? 0) + $amount;
        return $this;
    }

    public function deductEmailOtpBalance(int $amount): self
    {
        $this->emailOtpBalance = max(0, ($this->emailOtpBalance ?? 0) - $amount);
        return $this;
    }

    // Email Transactional Balance Methods
    
    public function getEmailTransactionalBalance(): int
    {
        return $this->emailTransactionalBalance ?? 0;
    }

    public function setEmailTransactionalBalance(int $emailTransactionalBalance): self
    {
        $this->emailTransactionalBalance = $emailTransactionalBalance;
        return $this;
    }

    public function addEmailTransactionalBalance(int $amount): self
    {
        $this->emailTransactionalBalance = ($this->emailTransactionalBalance ?? 0) + $amount;
        return $this;
    }

    public function deductEmailTransactionalBalance(int $amount): self
    {
        $this->emailTransactionalBalance = max(0, ($this->emailTransactionalBalance ?? 0) - $amount);
        return $this;
    }

    // TİB Methods

    public function isTibEnabled(): bool
    {
        return $this->tibEnabled ?? false;
    }

    public function setTibEnabled(bool $tibEnabled): self
    {
        $this->tibEnabled = $tibEnabled;
        return $this;
    }

    public function getTibMaxLists(): int
    {
        return $this->tibMaxLists ?? 2;
    }

    public function setTibMaxLists(int $tibMaxLists): self
    {
        $this->tibMaxLists = $tibMaxLists;
        return $this;
    }

    public function getTibMaxDomainsPerList(): int
    {
        return $this->tibMaxDomainsPerList ?? 30;
    }

    public function setTibMaxDomainsPerList(int $tibMaxDomainsPerList): self
    {
        $this->tibMaxDomainsPerList = $tibMaxDomainsPerList;
        return $this;
    }

    public function getTibExpiresAt(): ?DateTimeImmutable
    {
        return $this->tibExpiresAt;
    }

    public function setTibExpiresAt(?DateTimeImmutable $tibExpiresAt): self
    {
        $this->tibExpiresAt = $tibExpiresAt;
        return $this;
    }

    public function hasTibAccess(): bool
    {
        if (!$this->tibEnabled) {
            return false;
        }

        if ($this->tibExpiresAt === null) {
            return true;
        }

        return $this->tibExpiresAt > new DateTimeImmutable();
    }

    // WhatsApp Methods

    public function isWhatsappEnabled(): bool
    {
        return $this->whatsappEnabled ?? false;
    }

    public function setWhatsappEnabled(bool $whatsappEnabled): self
    {
        $this->whatsappEnabled = $whatsappEnabled;
        return $this;
    }

    public function getWhatsappMaxAccounts(): int
    {
        return $this->whatsappMaxAccounts ?? 1;
    }

    public function setWhatsappMaxAccounts(int $whatsappMaxAccounts): self
    {
        $this->whatsappMaxAccounts = $whatsappMaxAccounts;
        return $this;
    }

    public function getWhatsappMessageBalance(): int
    {
        return $this->whatsappMessageBalance ?? 0;
    }

    public function setWhatsappMessageBalance(int $whatsappMessageBalance): self
    {
        $this->whatsappMessageBalance = $whatsappMessageBalance;
        return $this;
    }

    public function addWhatsappMessageBalance(int $amount): self
    {
        $this->whatsappMessageBalance = ($this->whatsappMessageBalance ?? 0) + $amount;
        return $this;
    }

    public function deductWhatsappMessageBalance(int $amount): self
    {
        $this->whatsappMessageBalance = max(0, ($this->whatsappMessageBalance ?? 0) - $amount);
        return $this;
    }

    public function hasWhatsappMessageBalance(int $amount = 1): bool
    {
        return ($this->whatsappMessageBalance ?? 0) >= $amount;
    }

    public function getWhatsappOtpBalance(): int
    {
        return $this->whatsappOtpBalance ?? 0;
    }

    public function setWhatsappOtpBalance(int $whatsappOtpBalance): self
    {
        $this->whatsappOtpBalance = $whatsappOtpBalance;
        return $this;
    }

    public function addWhatsappOtpBalance(int $amount): self
    {
        $this->whatsappOtpBalance = ($this->whatsappOtpBalance ?? 0) + $amount;
        return $this;
    }

    public function deductWhatsappOtpBalance(int $amount): self
    {
        $this->whatsappOtpBalance = max(0, ($this->whatsappOtpBalance ?? 0) - $amount);
        return $this;
    }

    public function hasWhatsappOtpBalance(int $amount = 1): bool
    {
        return ($this->whatsappOtpBalance ?? 0) >= $amount;
    }

    public function getWhatsappExpiresAt(): ?DateTimeImmutable
    {
        return $this->whatsappExpiresAt;
    }

    public function setWhatsappExpiresAt(?DateTimeImmutable $whatsappExpiresAt): self
    {
        $this->whatsappExpiresAt = $whatsappExpiresAt;
        return $this;
    }

    public function hasWhatsappAccess(): bool
    {
        if (!$this->whatsappEnabled) {
            return false;
        }

        if ($this->whatsappExpiresAt === null) {
            return true;
        }

        return $this->whatsappExpiresAt > new DateTimeImmutable();
    }

    // Telegram
    public function isTelegramEnabled(): bool
    {
        return $this->telegramEnabled;
    }

    public function setTelegramEnabled(bool $telegramEnabled): self
    {
        $this->telegramEnabled = $telegramEnabled;
        return $this;
    }

    public function getTelegramMaxBots(): int
    {
        return $this->telegramMaxBots ?? 1;
    }

    public function setTelegramMaxBots(int $telegramMaxBots): self
    {
        $this->telegramMaxBots = $telegramMaxBots;
        return $this;
    }

    public function getTelegramExpiresAt(): ?DateTimeImmutable
    {
        return $this->telegramExpiresAt;
    }

    public function setTelegramExpiresAt(?DateTimeImmutable $telegramExpiresAt): self
    {
        $this->telegramExpiresAt = $telegramExpiresAt;
        return $this;
    }

    public function hasTelegramAccess(): bool
    {
        if (!$this->telegramEnabled) {
            return false;
        }

        if ($this->telegramExpiresAt === null) {
            return true;
        }

        return $this->telegramExpiresAt > new DateTimeImmutable();
    }

    public function getUrlShortenerEnabled(): bool
    {
        return $this->urlShortenerEnabled ?? true;
    }

    public function setUrlShortenerEnabled(bool $urlShortenerEnabled): self
    {
        $this->urlShortenerEnabled = $urlShortenerEnabled;
        return $this;
    }

    public function getUrlShortenerMaxUrls(): ?int
    {
        return $this->urlShortenerMaxUrls;
    }

    public function setUrlShortenerMaxUrls(?int $urlShortenerMaxUrls): self
    {
        $this->urlShortenerMaxUrls = $urlShortenerMaxUrls;
        return $this;
    }

    public function getUrlShortenerAllowedDomains(): ?array
    {
        return $this->urlShortenerAllowedDomains;
    }

    public function setUrlShortenerAllowedDomains(?array $urlShortenerAllowedDomains): self
    {
        $this->urlShortenerAllowedDomains = $urlShortenerAllowedDomains;
        return $this;
    }

    public function hasUrlShortenerAccess(): bool
    {
        return $this->urlShortenerEnabled;
    }

    public function canCreateMoreUrls(): bool
    {
        if (!$this->urlShortenerEnabled) {
            return false;
        }

        // Sınırsız
        if ($this->urlShortenerMaxUrls === null) {
            return true;
        }

        // Mevcut URL sayısını kontrol et (bu metod controller'da çağrılmalı)
        return true;
    }

    public function isTelegramCommandsEnabled(): bool
    {
        return $this->telegramCommandsEnabled ?? true;
    }

    public function setTelegramCommandsEnabled(bool $telegramCommandsEnabled): self
    {
        $this->telegramCommandsEnabled = $telegramCommandsEnabled;
        return $this;
    }

    // Hesap Yöneticisi (Account Manager) Metodları

    public function getParentUser(): ?User
    {
        return $this->parentUser;
    }

    public function setParentUser(?User $parentUser): self
    {
        $this->parentUser = $parentUser;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getChildUsers(): Collection
    {
        return $this->childUsers;
    }

    public function addChildUser(User $childUser): self
    {
        if (!$this->childUsers->contains($childUser)) {
            $this->childUsers->add($childUser);
            $childUser->setParentUser($this);
        }
        return $this;
    }

    public function removeChildUser(User $childUser): self
    {
        if ($this->childUsers->removeElement($childUser)) {
            if ($childUser->getParentUser() === $this) {
                $childUser->setParentUser(null);
            }
        }
        return $this;
    }

    /**
     * Bu kullanıcı bir Hesap Yöneticisi mi? (Alt hesapları var mı?)
     */
    public function isAccountManager(): bool
    {
        return $this->childUsers->count() > 0;
    }

    /**
     * Bu kullanıcı bir alt hesap mı? (Parent user'ı var mı?)
     */
    public function isChildAccount(): bool
    {
        return $this->parentUser !== null;
    }

    /**
     * Belirli bir kullanıcının alt hesabı mı?
     */
    public function isChildOf(?User $parentUser): bool
    {
        if ($parentUser === null) {
            return false;
        }
        return $this->parentUser === $parentUser;
    }

    /**
     * Belirli bir kullanıcı bu kullanıcının alt hesabı mı?
     */
    public function isParentOf(User $childUser): bool
    {
        return $this->childUsers->contains($childUser);
    }

    /**
     * İzinli sayfaları getir
     */
    public function getAllowedPages(): ?array
    {
        return $this->allowedPages;
    }

    /**
     * İzinli sayfaları ayarla
     */
    public function setAllowedPages(?array $allowedPages): self
    {
        $this->allowedPages = $allowedPages;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    /**
     * Belirli bir sayfaya erişim izni var mı?
     */
    public function canAccessPage(string $pagePath): bool
    {
        // Null ise tüm sayfalara erişebilir (role bazlı kontrol)
        if ($this->allowedPages === null) {
            return true;
        }

        // Boş array ise hiçbir sayfaya erişemez
        if (empty($this->allowedPages)) {
            return false;
        }

        // Sayfa yolunu normalize et (başında / olmalı)
        $normalizedPath = '/' . ltrim($pagePath, '/');

        // Tam eşleşme kontrolü
        if (in_array($normalizedPath, $this->allowedPages, true)) {
            return true;
        }

        // Prefix kontrolü (örn: /orders alt sayfaları için)
        foreach ($this->allowedPages as $allowedPath) {
            $normalizedAllowedPath = '/' . ltrim($allowedPath, '/');
            if (strpos($normalizedPath, $normalizedAllowedPath) === 0) {
                return true;
            }
        }

        return false;
    }

    // SMM Balance Methods
    public function getSmmBalance(): float
    {
        return $this->smmBalance ?? 0.00;
    }

    public function setSmmBalance(float $smmBalance): self
    {
        $this->smmBalance = $smmBalance;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function addSmmBalance(float $amount): self
    {
        $this->smmBalance = ($this->smmBalance ?? 0.00) + $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function deductSmmBalance(float $amount): self
    {
        $this->smmBalance = max(0, ($this->smmBalance ?? 0.00) - $amount);
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }
}

