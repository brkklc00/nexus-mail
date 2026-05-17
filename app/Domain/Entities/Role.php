<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DateTimeImmutable;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isReadonly = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'roles')]
    private Collection $users;

    /**
     * @var Collection<int, RolePermission>
     */
    #[ORM\OneToMany(targetEntity: RolePermission::class, mappedBy: 'role', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $permissions;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->permissions = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
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
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function isReadonly(): bool
    {
        return $this->isReadonly;
    }

    public function setIsReadonly(bool $isReadonly): self
    {
        $this->isReadonly = $isReadonly;
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
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    /**
     * @return Collection<int, RolePermission>
     */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(RolePermission $permission): self
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
            $permission->setRole($this);
        }
        return $this;
    }

    public function hasPermission(string $permissionKey, string $action = 'read'): bool
    {
        foreach ($this->permissions as $rolePermission) {
            if ($rolePermission->getPermission()->getKey() === $permissionKey) {
                return match($action) {
                    'create' => $rolePermission->canCreate(),
                    'read' => $rolePermission->canRead(),
                    'update' => $rolePermission->canUpdate(),
                    'delete' => $rolePermission->canDelete(),
                    default => false,
                };
            }
        }
        return false;
    }
}

