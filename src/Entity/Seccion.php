<?php

namespace App\Entity;

use App\Repository\SeccionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nodo del árbol de navegación de un proyecto.
 * En Semantica ERP los niveles son: aplicación (Transporte) > menú (Movimiento) > grupo (Logística),
 * igual que el menú de teneso; otros proyectos pueden usar la profundidad que necesiten.
 */
#[ORM\Entity(repositoryClass: SeccionRepository::class)]
#[ORM\Table(name: 'seccion')]
#[ORM\Index(name: 'idx_seccion_proyecto_padre', columns: ['proyecto_id', 'padre_id'])]
class Seccion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'secciones')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Proyecto $proyecto = null;

    #[ORM\ManyToOne(inversedBy: 'hijos')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Seccion $padre = null;

    /** @var Collection<int, Seccion> */
    #[ORM\OneToMany(targetEntity: Seccion::class, mappedBy: 'padre')]
    #[ORM\OrderBy(['orden' => \SortDirection::Ascending])]
    private Collection $hijos;

    /** @var Collection<int, Documento> */
    #[ORM\OneToMany(targetEntity: Documento::class, mappedBy: 'seccion')]
    #[ORM\OrderBy(['orden' => \SortDirection::Ascending, 'titulo' => \SortDirection::Ascending])]
    private Collection $documentos;

    /** Identificador en minúsculas, usado en las URLs (ej. "logistica"). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $nombre = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private string $titulo = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icono = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $orden = 0;

    public function __construct()
    {
        $this->hijos = new ArrayCollection();
        $this->documentos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getRuta();
    }

    /**
     * Títulos desde la raíz hasta esta sección, ej. "Transporte ⇾ Movimiento ⇾ Logística".
     */
    public function getRuta(string $separador = ' ⇾ '): string
    {
        $titulos = [];
        for ($seccion = $this; null !== $seccion; $seccion = $seccion->getPadre()) {
            array_unshift($titulos, $seccion->getTitulo());
        }

        return implode($separador, $titulos);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProyecto(): ?Proyecto
    {
        return $this->proyecto;
    }

    public function setProyecto(?Proyecto $proyecto): static
    {
        $this->proyecto = $proyecto;

        return $this;
    }

    public function getPadre(): ?Seccion
    {
        return $this->padre;
    }

    public function setPadre(?Seccion $padre): static
    {
        $this->padre = $padre;

        return $this;
    }

    /** @return Collection<int, Seccion> */
    public function getHijos(): Collection
    {
        return $this->hijos;
    }

    /** @return Collection<int, Documento> */
    public function getDocumentos(): Collection
    {
        return $this->documentos;
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

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): static
    {
        $this->titulo = $titulo;

        return $this;
    }

    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): static
    {
        $this->icono = $icono;

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
}
