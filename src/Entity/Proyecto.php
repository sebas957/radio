<?php

namespace App\Entity;

use App\Repository\ProyectoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Producto documentado (Semantica ERP, RedDoc, Ruteo...). Viene de la tabla proyecto de carbono.
 */
#[ORM\Entity(repositoryClass: ProyectoRepository::class)]
#[ORM\Table(name: 'proyecto')]
#[UniqueEntity(fields: ['slug'], message: 'Ya existe un proyecto con ese identificador')]
class Proyecto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $nombre = '';

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Solo minúsculas, números y guiones')]
    private string $slug = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $activo = true;

    /** @var Collection<int, Seccion> */
    #[ORM\OneToMany(targetEntity: Seccion::class, mappedBy: 'proyecto')]
    #[ORM\OrderBy(['orden' => \SortDirection::Ascending])]
    private Collection $secciones;

    public function __construct()
    {
        $this->secciones = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): static
    {
        $this->orden = $orden;

        return $this;
    }

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): static
    {
        $this->activo = $activo;

        return $this;
    }

    /** @return Collection<int, Seccion> */
    public function getSecciones(): Collection
    {
        return $this->secciones;
    }
}
