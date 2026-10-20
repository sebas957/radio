<?php

namespace App\Entity;

use App\Repository\DocumentoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Página de documentación en Markdown. Reemplaza a Documentacion de carbono y a los
 * archivos /documentacion/{id}.md que editaba teneso.
 *
 * El id se conserva al importar desde carbono porque holmio enlaza la ayuda por ese número.
 */
#[ORM\Entity(repositoryClass: DocumentoRepository::class)]
#[ORM\Table(name: 'documento')]
#[ORM\Index(name: 'idx_documento_modelo', columns: ['modelo'])]
#[ORM\HasLifecycleCallbacks]
class Documento
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Seleccione el proyecto')]
    private ?Proyecto $proyecto = null;

    #[ORM\ManyToOne(inversedBy: 'documentos')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Seccion $seccion = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $titulo = '';

    /** Identificador en minúsculas dentro de la sección (ej. "recogida"). */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Solo minúsculas, números y guiones')]
    private ?string $nombre = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $contenido = '';

    /** Entidad del ERP a la que corresponde la ayuda (ej. "TteGuia"). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $modelo = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $video = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $orden = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $publicado = true;

    #[ORM\Column]
    private \DateTimeImmutable $fechaActualizacion;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Usuario $actualizadoPor = null;

    public function __construct()
    {
        $this->fechaActualizacion = new \DateTimeImmutable();
    }

    /**
     * La sección debe ser del mismo proyecto: si no, el documento no aparece en el menú de
     * ningún proyecto (el árbol de cada proyecto solo recorre sus propias secciones).
     */
    #[Assert\Callback]
    public function validarSeccion(ExecutionContextInterface $contexto): void
    {
        if ($this->seccion && $this->proyecto && $this->seccion->getProyecto() !== $this->proyecto) {
            $contexto->buildViolation('La sección no pertenece al proyecto seleccionado.')
                ->atPath('seccion')
                ->addViolation();
        }
    }

    #[ORM\PreUpdate]
    public function marcarActualizacion(): void
    {
        $this->fechaActualizacion = new \DateTimeImmutable();
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

    public function getSeccion(): ?Seccion
    {
        return $this->seccion;
    }

    public function setSeccion(?Seccion $seccion): static
    {
        $this->seccion = $seccion;

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

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): static
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getContenido(): string
    {
        return $this->contenido;
    }

    public function setContenido(?string $contenido): static
    {
        $this->contenido = $contenido ?? '';

        return $this;
    }

    public function getModelo(): ?string
    {
        return $this->modelo;
    }

    public function setModelo(?string $modelo): static
    {
        $this->modelo = $modelo;

        return $this;
    }

    public function getVideo(): ?string
    {
        return $this->video;
    }

    public function setVideo(?string $video): static
    {
        $this->video = $video;

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

    public function isPublicado(): bool
    {
        return $this->publicado;
    }

    public function setPublicado(bool $publicado): static
    {
        $this->publicado = $publicado;

        return $this;
    }

    public function getFechaActualizacion(): \DateTimeImmutable
    {
        return $this->fechaActualizacion;
    }

    public function getActualizadoPor(): ?Usuario
    {
        return $this->actualizadoPor;
    }

    public function setActualizadoPor(?Usuario $actualizadoPor): static
    {
        $this->actualizadoPor = $actualizadoPor;

        return $this;
    }
}
