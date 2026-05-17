<?php

declare(strict_types=1);

namespace App\Domain\Entities;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'role_permissions')]
class RolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'permissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    #[ORM\ManyToOne(targetEntity: Permission::class, inversedBy: 'rolePermissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Permission $permission;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canRead = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canCreate = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canUpdate = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canDelete = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getPermission(): Permission
    {
        return $this->permission;
    }

    public function setPermission(Permission $permission): self
    {
        $this->permission = $permission;
        return $this;
    }

    public function canRead(): bool
    {
        return $this->canRead;
    }

    public function setCanRead(bool $canRead): self
    {
        $this->canRead = $canRead;
        return $this;
    }

    public function canCreate(): bool
    {
        return $this->canCreate;
    }

    public function setCanCreate(bool $canCreate): self
    {
        $this->canCreate = $canCreate;
        return $this;
    }

    public function canUpdate(): bool
    {
        return $this->canUpdate;
    }

    public function setCanUpdate(bool $canUpdate): self
    {
        $this->canUpdate = $canUpdate;
        return $this;
    }

    public function canDelete(): bool
    {
        return $this->canDelete;
    }

    public function setCanDelete(bool $canDelete): self
    {
        $this->canDelete = $canDelete;
        return $this;
    }
}

