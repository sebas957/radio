<?php

namespace App\Repository;

use App\Entity\Seccion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seccion>
 */
class SeccionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seccion::class);
    }

    /**
     * Todas las secciones ordenadas como árbol (padre seguido de sus hijos), para selects y listados.
     *
     * @return list<Seccion>
     */
    public function arbol(): array
    {
        $secciones = $this->createQueryBuilder('s')
            ->addSelect('p')
            ->join('s.proyecto', 'p')
            ->orderBy('p.orden')
            ->addOrderBy('p.nombre')
            ->addOrderBy('s.orden')
            ->addOrderBy('s.titulo')
            ->getQuery()
            ->getResult();

        $hijosPorPadre = [];
        foreach ($secciones as $seccion) {
            $hijosPorPadre[$seccion->getPadre()?->getId() ?? 0][] = $seccion;
        }

        $resultado = [];
        $recorrer = function (int $padreId) use (&$recorrer, &$resultado, $hijosPorPadre): void {
            foreach ($hijosPorPadre[$padreId] ?? [] as $seccion) {
                $resultado[] = $seccion;
                $recorrer($seccion->getId());
            }
        };
        $recorrer(0);

        return $resultado;
    }
}
