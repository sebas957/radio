<?php

namespace App\Documentacion;

use App\Entity\Documento;
use App\Entity\Proyecto;
use App\Entity\Seccion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Árbol de navegación de un proyecto para el menú lateral del visor.
 *
 * Carga las secciones y solo id/título de los documentos (sin el contenido) en dos consultas,
 * y quita las ramas que no tienen documentos visibles.
 */
class Navegacion
{
    /** @var list<array{seccion: Seccion, hijos: list<array>, documentos: list<array{id: int, titulo: string}>, total: int}> */
    private array $raices = [];

    /** @var list<array{id: int, titulo: string}> */
    private array $sinSeccion = [];

    /** @var list<array{id: int, titulo: string, seccion: ?int}> Documentos en el orden en que aparecen en el menú */
    private array $orden = [];

    /** @var array<int, Seccion> */
    private array $secciones = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Proyecto $proyecto,
        bool $incluirBorradores,
    ) {
        $this->construir($incluirBorradores);
    }

    public function getProyecto(): Proyecto
    {
        return $this->proyecto;
    }

    /** @return list<array{seccion: Seccion, hijos: list<array>, documentos: list<array{id: int, titulo: string}>, total: int}> */
    public function getRaices(): array
    {
        return $this->raices;
    }

    /** @return list<array{id: int, titulo: string}> */
    public function getSinSeccion(): array
    {
        return $this->sinSeccion;
    }

    public function getTotalDocumentos(): int
    {
        return count($this->orden);
    }

    /**
     * Ids de la sección y sus ancestros, para dejar abiertas esas ramas en el menú.
     *
     * @return array<int, true>
     */
    public function abiertas(?Seccion $seccion): array
    {
        $ids = [];
        for (; null !== $seccion; $seccion = $seccion->getPadre()) {
            $ids[$seccion->getId()] = true;
        }

        return $ids;
    }

    /**
     * Nodo del árbol de una sección (con sus hijos y documentos ya filtrados).
     *
     * @return array{seccion: Seccion, hijos: list<array>, documentos: list<array{id: int, titulo: string}>, total: int}|null
     */
    public function nodo(Seccion $seccion): ?array
    {
        $buscar = function (array $nodos) use (&$buscar, $seccion): ?array {
            foreach ($nodos as $nodo) {
                if ($nodo['seccion']->getId() === $seccion->getId()) {
                    return $nodo;
                }
                if ($encontrado = $buscar($nodo['hijos'])) {
                    return $encontrado;
                }
            }

            return null;
        };

        return $buscar($this->raices);
    }

    /**
     * Documentos anterior y siguiente según el orden del menú.
     *
     * @return array{anterior: ?array{id: int, titulo: string}, siguiente: ?array{id: int, titulo: string}}
     */
    public function vecinos(int $documentoId): array
    {
        $posicion = array_search($documentoId, array_column($this->orden, 'id'), true);
        if (false === $posicion) {
            return ['anterior' => null, 'siguiente' => null];
        }

        return [
            'anterior' => $this->orden[$posicion - 1] ?? null,
            'siguiente' => $this->orden[$posicion + 1] ?? null,
        ];
    }

    private function construir(bool $incluirBorradores): void
    {
        $secciones = $this->em->getRepository(Seccion::class)->findBy(['proyecto' => $this->proyecto], ['orden' => 'ASC', 'titulo' => 'ASC']);

        $qb = $this->em->createQueryBuilder()
            ->select('d.id', 'd.titulo', 'IDENTITY(d.seccion) AS seccion')
            ->from(Documento::class, 'd')
            ->where('d.proyecto = :proyecto')
            ->setParameter('proyecto', $this->proyecto)
            ->orderBy('d.orden')
            ->addOrderBy('d.titulo');
        if (!$incluirBorradores) {
            $qb->andWhere('d.publicado = true');
        }

        $documentosPorSeccion = [];
        foreach ($qb->getQuery()->getArrayResult() as $documento) {
            $documentosPorSeccion[(int) $documento['seccion']][] = ['id' => $documento['id'], 'titulo' => $documento['titulo']];
        }

        $hijosPorPadre = [];
        foreach ($secciones as $seccion) {
            $this->secciones[$seccion->getId()] = $seccion;
            $hijosPorPadre[$seccion->getPadre()?->getId() ?? 0][] = $seccion;
        }

        $armar = function (int $padreId) use (&$armar, $hijosPorPadre, $documentosPorSeccion): array {
            $nodos = [];
            foreach ($hijosPorPadre[$padreId] ?? [] as $seccion) {
                $documentos = $documentosPorSeccion[$seccion->getId()] ?? [];
                foreach ($documentos as $documento) {
                    $this->orden[] = $documento + ['seccion' => $seccion->getId()];
                }
                $hijos = $armar($seccion->getId());
                if ($documentos || $hijos) {
                    $nodos[] = [
                        'seccion' => $seccion,
                        'hijos' => $hijos,
                        'documentos' => $documentos,
                        // Documentos de la sección y de todas sus subsecciones.
                        'total' => count($documentos) + array_sum(array_column($hijos, 'total')),
                    ];
                }
            }

            return $nodos;
        };
        $this->raices = $armar(0);

        $this->sinSeccion = $documentosPorSeccion[0] ?? [];
        foreach ($this->sinSeccion as $documento) {
            $this->orden[] = $documento + ['seccion' => null];
        }
    }
}
