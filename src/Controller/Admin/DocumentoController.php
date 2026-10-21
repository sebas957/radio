<?php

namespace App\Controller\Admin;

use App\Entity\Documento;
use App\Entity\Usuario;
use App\Form\DocumentoType;
use App\Markdown\Renderizador;
use App\Repository\DocumentoRepository;
use App\Repository\ProyectoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/documento')]
#[IsGranted('ROLE_EDITOR')]
class DocumentoController extends AbstractController
{
    private const POR_PAGINA = 50;

    #[Route('', name: 'admin_documento_lista', methods: ['GET'])]
    public function lista(Request $request, DocumentoRepository $documentoRepository, ProyectoRepository $proyectoRepository): Response
    {
        // getInt() responde 400 con un valor vacío ("Todos los proyectos" envía proyecto=), así que se convierte a mano.
        $proyectoId = (int) $request->query->getString('proyecto');
        $proyecto = $proyectoId > 0 ? $proyectoRepository->find($proyectoId) : null;
        $busqueda = $request->query->getString('q');
        $pagina = max(1, (int) $request->query->getString('pagina', '1'));
        $documentos = $documentoRepository->listaAdmin($proyecto, $busqueda, $pagina, self::POR_PAGINA);

        return $this->render('admin/documento/lista.html.twig', [
            'documentos' => $documentos,
            'proyectos' => $proyectoRepository->findBy([], ['orden' => 'ASC', 'nombre' => 'ASC']),
            'proyecto' => $proyecto,
            'busqueda' => $busqueda,
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil(count($documentos) / self::POR_PAGINA)),
        ]);
    }

    #[Route('/nuevo', name: 'admin_documento_nuevo', methods: ['GET', 'POST'])]
    public function nuevo(Request $request, EntityManagerInterface $em, #[CurrentUser] Usuario $usuario): Response
    {
        return $this->formulario($request, $em, $usuario, new Documento());
    }

    #[Route('/{id}/editar', name: 'admin_documento_editar', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editar(Request $request, EntityManagerInterface $em, #[CurrentUser] Usuario $usuario, Documento $documento): Response
    {
        return $this->formulario($request, $em, $usuario, $documento);
    }

    #[Route('/{id}/eliminar', name: 'admin_documento_eliminar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function eliminar(Request $request, EntityManagerInterface $em, Documento $documento): Response
    {
        if ($this->isCsrfTokenValid('eliminar-documento-'.$documento->getId(), $request->getPayload()->getString('_token'))) {
            $em->remove($documento);
            $em->flush();
            $this->addFlash('exito', sprintf('Se eliminó "%s".', $documento->getTitulo()));
        }

        return $this->redirectToRoute('admin_documento_lista', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Vista previa del editor: usa el mismo renderizador que el visor.
     */
    #[Route('/vista-previa', name: 'admin_documento_vista_previa', methods: ['POST'])]
    public function vistaPrevia(Request $request, Renderizador $renderizador): Response
    {
        return new Response($renderizador->html($request->getContent()));
    }

    private function formulario(Request $request, EntityManagerInterface $em, Usuario $usuario, Documento $documento): Response
    {
        $form = $this->createForm(DocumentoType::class, $documento);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $documento->setActualizadoPor($usuario);
            $em->persist($documento);
            $em->flush();
            $this->addFlash('exito', sprintf('Se guardó "%s".', $documento->getTitulo()));

            return $this->redirectToRoute('admin_documento_editar', ['id' => $documento->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/documento/formulario.html.twig', [
            'documento' => $documento,
            'form' => $form,
        ]);
    }
}
