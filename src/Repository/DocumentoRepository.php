<?php

namespace App\Repository;

use App\Entity\Documento;
use App\Entity\Proyecto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Documento>
 */
class DocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Documento::class);
    }

    /**
     * Listado del administrador. $busqueda filtra por título, modelo o id.
     *
     * @return Paginator<Documento>
     */
    public function listaAdmin(?Proyecto $proyecto, ?string $busqueda, int $pagina, int $porPagina): Paginator
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('p', 's')
            ->join('d.proyecto', 'p')
            ->leftJoin('d.seccion', 's')
            ->orderBy('d.fechaActualizacion', 'DESC')
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina);

        if ($proyecto) {
            $qb->andWhere('d.proyecto = :proyecto')->setParameter('proyecto', $proyecto);
        }

        $busqueda = trim((string) $busqueda);
        if ('' !== $busqueda) {
            if (ctype_digit($busqueda)) {
                $qb->andWhere('d.id = :id')->setParameter('id', (int) $busqueda);
            } else {
                $qb->andWhere('LOWER(d.titulo) LIKE :texto OR LOWER(d.modelo) LIKE :texto')
                    ->setParameter('texto', '%'.mb_strtolower($busqueda).'%');
            }
        }

        return new Paginator($qb);
    }

    /**
     * Búsqueda de texto completo en español (PostgreSQL), sin distinguir tildes.
     * El título y el modelo pesan más que el contenido. También encuentra palabras incompletas
     * en el título ("factur" encuentra "Facturación"). El fragmento se resalta sobre el texto
     * original, así que conserva las tildes aunque no resalte palabras escritas sin ellas.
     *
     * @return list<array{id: int, titulo: string, proyecto_id: int, seccion_id: ?int, fragmento: string}>
     */
    public function buscar(string $texto, ?Proyecto $proyecto, bool $incluirBorradores, int $limite = 50): array
    {
        $sql = <<<'SQL'
            WITH consulta AS (
                SELECT websearch_to_tsquery('spanish', f_unaccent(:texto)) AS q,
                       websearch_to_tsquery('spanish', :texto) AS q_resaltado
            ), documentos AS (
                SELECT d.*,
                       setweight(to_tsvector('spanish', f_unaccent(d.titulo || ' ' || coalesce(d.modelo, ''))), 'A')
                    || setweight(to_tsvector('spanish', f_unaccent(d.contenido)), 'B') AS vector
                FROM documento d
                JOIN proyecto p ON p.id = d.proyecto_id AND p.activo
                WHERE (:proyecto::int IS NULL OR d.proyecto_id = :proyecto::int)
                  AND (:borradores OR d.publicado)
            )
            SELECT d.id, d.titulo, d.proyecto_id, d.seccion_id,
                   ts_headline('spanish', regexp_replace(d.contenido, '<[^>]+>|[#*_`>|\[\]]', ' ', 'g'), c.q_resaltado,
                               'MaxWords=30, MinWords=12, MaxFragments=2, FragmentDelimiter=" … ", StartSel=<mark>, StopSel=</mark>') AS fragmento
            FROM documentos d, consulta c
            WHERE d.vector @@ c.q
               OR f_unaccent(d.titulo) ILIKE '%' || f_unaccent(:texto) || '%'
            ORDER BY ts_rank(d.vector, c.q, 1) + CASE WHEN f_unaccent(d.titulo) ILIKE '%' || f_unaccent(:texto) || '%' THEN 1 ELSE 0 END DESC,
                     d.titulo
            LIMIT :limite
            SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, [
            'texto' => $texto,
            'proyecto' => $proyecto?->getId(),
            'borradores' => $incluirBorradores,
            'limite' => $limite,
        ], [
            'borradores' => 'boolean',
            'limite' => 'integer',
        ]);
    }
}
