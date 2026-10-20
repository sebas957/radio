<?php

namespace App\Controller;

use App\Documentacion\Navegacion;
use App\Entity\Documento;
use App\Entity\Proyecto;
use App\Entity\Seccion;
use App\Markdown\Renderizador;
use App\Repository\DocumentoRepository;
use App\Repository\ProyectoRepository;
use App\Repository\SeccionRepository;
use App\Twig\DocumentacionExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Visor de la documentación: proyectos, secciones, documentos y búsqueda.
 */
class VisorController extends AbstractController
{
    private const MAX_RESULTADOS = 200;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/', name: 'inicio', methods: ['GET'])]
    public function inicio(ProyectoRepository $proyectoRepository): Response
    {
        $publicados = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.proyecto) AS proyecto', 'COUNT(d.id) AS total')
            ->from(Documento::class, 'd')
            ->where('d.publicado = true')
            ->groupBy('d.proyecto')
            ->getQuery()
            ->getArrayResult();
        $totales = array_column($publicados, 'total', 'proyecto');

        $proyectos = array_filter(
            $proyectoRepository->findBy(['activo' => true], ['orden' => 'ASC', 'nombre' => 'ASC']),
            fn (Proyecto $p) => ($totales[$p->getId()] ?? 0) > 0 || $this->isGranted('ROLE_EDITOR'),
        );

        return $this->render('visor/inicio.html.twig', [
            'proyectos' => $proyectos,
            'totales' => $totales,
        ]);
    }

    #[Route('/docs/{proyecto}', name: 'visor_proyecto', methods: ['GET'])]
    public function proyecto(#[MapEntity(mapping: ['proyecto' => 'slug'])] Proyecto $proyecto): Response
    {
        $navegacion = $this->navegacion($proyecto);

        return $this->render('visor/seccion.html.twig', [
            'navegacion' => $navegacion,
            'seccion' => null,
            'nodos' => $navegacion->getRaices(),
            'documentos' => $navegacion->getSinSeccion(),
        ]);
    }

    #[Route('/docs/{proyecto}/seccion/{id}', name: 'visor_seccion', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function seccion(
        #[MapEntity(mapping: ['proyecto' => 'slug'])] Proyecto $proyecto,
        #[MapEntity(id: 'id')] Seccion $seccion,
    ): Response {
        if ($seccion->getProyecto() !== $proyecto) {
            throw $this->createNotFoundException();
        }

        $navegacion = $this->navegacion($proyecto);
        $nodo = $navegacion->nodo($seccion) ?? throw $this->createNotFoundException('La sección no tiene documentos.');

        return $this->render('visor/seccion.html.twig', [
            'navegacion' => $navegacion,
            'seccion' => $seccion,
            'nodos' => $nodo['hijos'],
            'documentos' => $nodo['documentos'],
        ]);
    }

    #[Route('/docs/{proyecto}/{id}/{slug}', name: 'visor_documento', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]*'], defaults: ['slug' => ''], methods: ['GET'])]
    public function documento(
        string $proyecto,
        string $slug,
        #[MapEntity(id: 'id')] Documento $documento,
        Renderizador $renderizador,
        DocumentacionExtension $documentacion,
    ): Response {
        $this->verificarVisible($documento);

        // Si cambió el título o el proyecto, se redirige a la URL vigente para no romper enlaces.
        if ($proyecto !== $documento->getProyecto()->getSlug() || $slug !== $documentacion->slug($documento->getTitulo())) {
            return $this->redirect($documentacion->urlDocumento($documento->getProyecto()->getSlug(), $documento->getId(), $documento->getTitulo()), Response::HTTP_MOVED_PERMANENTLY);
        }

        $navegacion = $this->navegacion($documento->getProyecto());

        return $this->render('visor/documento.html.twig', [
            'navegacion' => $navegacion,
            'documento' => $documento,
            'contenido' => $renderizador->documento($documento->getContenido()),
            'vecinos' => $navegacion->vecinos($documento->getId()),
        ]);
    }

    /**
     * Enlace corto por número, el mismo que usaba la ayuda de holmio con los ids de carbono.
     */
    #[Route('/documento/{id}', name: 'visor_documento_id', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documentoPorId(Documento $documento, DocumentacionExtension $documentacion): Response
    {
        $this->verificarVisible($documento);

        return $this->redirect($documentacion->urlDocumento($documento->getProyecto()->getSlug(), $documento->getId(), $documento->getTitulo()));
    }

    #[Route('/buscar', name: 'visor_buscar', methods: ['GET'])]
    public function buscar(Request $request, DocumentoRepository $documentoRepository, ProyectoRepository $proyectoRepository, SeccionRepository $seccionRepository): Response
    {
        $texto = trim($request->query->getString('q'));
        $proyecto = $request->query->getString('proyecto') ? $proyectoRepository->findOneBy(['slug' => $request->query->getString('proyecto')]) : null;
        // Se busca en todos los proyectos para poder mostrar cuántos resultados hay en cada uno;
        // el proyecto elegido solo filtra lo que se muestra.
        $todos = mb_strlen($texto) >= 2 ? $documentoRepository->buscar($texto, null, $this->isGranted('ROLE_EDITOR'), self::MAX_RESULTADOS) : [];
        $porProyecto = array_count_values(array_column($todos, 'proyecto_id'));
        $resultados = $proyecto
            ? array_values(array_filter($todos, fn (array $r) => $r['proyecto_id'] === $proyecto->getId()))
            : $todos;

        // Se cargan de una vez los proyectos y secciones de los resultados para mostrar su ubicación.
        $proyectos = [];
        foreach ($proyectoRepository->findBy([], ['orden' => 'ASC', 'nombre' => 'ASC']) as $p) {
            $proyectos[$p->getId()] = $p;
        }
        // Todas las secciones de esos proyectos, así getRuta() no consulta un padre por vez.
        $secciones = [];
        if ($resultados) {
            foreach ($seccionRepository->findBy(['proyecto' => array_unique(array_column($resultados, 'proyecto_id'))]) as $s) {
                $secciones[$s->getId()] = $s;
            }
        }

        return $this->render('visor/buscar.html.twig', [
            'texto' => $texto,
            'proyecto' => $proyecto,
            'resultados' => $resultados,
            'total' => count($todos),
            'limite' => self::MAX_RESULTADOS,
            'por_proyecto' => $porProyecto,
            'proyectos' => $proyectos,
            'secciones' => $secciones,
        ]);
    }

    private function navegacion(Proyecto $proyecto): Navegacion
    {
        if (!$proyecto->isActivo() && !$this->isGranted('ROLE_EDITOR')) {
            throw $this->createNotFoundException();
        }

        return new Navegacion($this->em, $proyecto, $this->isGranted('ROLE_EDITOR'));
    }

    /**
     * Los borradores y los documentos de proyectos inactivos solo los ven los editores.
     */
    private function verificarVisible(Documento $documento): void
    {
        if ($this->isGranted('ROLE_EDITOR')) {
            return;
        }
        if (!$documento->isPublicado() || !$documento->getProyecto()->isActivo()) {
            throw new NotFoundHttpException();
        }
    }
}
