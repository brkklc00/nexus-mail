<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;
use DateTime;

#[ORM\Entity]
#[ORM\Table(name: 'email_phonebooks')]
#[ORM\HasLifecycleCallbacks]
class EmailPhonebook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', name: 'total_contacts')]
    private int $totalContacts = 0;

    #[ORM\OneToMany(mappedBy: 'phonebook', targetEntity: EmailContact::class, cascade: ['persist', 'remove'])]
    private Collection $contacts;

    #[ORM\Column(type: 'datetime', name: 'created_at')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', name: 'updated_at')]
    private DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
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

    public function getTotalContacts(): int
    {
        return $this->totalContacts;
    }

    public function setTotalContacts(int $totalContacts): self
    {
        $this->totalContacts = $totalContacts;
        return $this;
    }

    public function incrementTotalContacts(int $count = 1): self
    {
        $this->totalContacts += $count;
        return $this;
    }

    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(EmailContact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setPhonebook($this);
            $this->incrementTotalContacts();
        }
        return $this;
    }

    public function removeContact(EmailContact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            $this->totalContacts = max(0, $this->totalContacts - 1);
        }
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
}

