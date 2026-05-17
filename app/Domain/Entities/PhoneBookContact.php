<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'phone_book_contacts')]
class PhoneBookContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PhoneBook::class, inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PhoneBook $phoneBook;

    #[ORM\Column(type: 'string', length: 20)]
    private string $phoneE164;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $rawValue = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoneBook(): PhoneBook
    {
        return $this->phoneBook;
    }

    public function setPhoneBook(PhoneBook $phoneBook): self
    {
        $this->phoneBook = $phoneBook;
        return $this;
    }

    public function getPhoneE164(): string
    {
        return $this->phoneE164;
    }

    public function setPhoneE164(string $phoneE164): self
    {
        $this->phoneE164 = $phoneE164;
        return $this;
    }

    public function getRawValue(): ?string
    {
        return $this->rawValue;
    }

    public function setRawValue(?string $rawValue): self
    {
        $this->rawValue = $rawValue;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

