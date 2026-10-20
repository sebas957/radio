<?php

namespace App\Form;

use App\Entity\Documento;
use App\Entity\Proyecto;
use App\Entity\Seccion;
use App\Repository\SeccionRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DocumentoType extends AbstractType
{
    public function __construct(private readonly SeccionRepository $seccionRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, ['label' => 'Título', 'empty_data' => ''])
            ->add('proyecto', EntityType::class, [
                'class' => Proyecto::class,
                'choice_label' => 'nombre',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('p')->orderBy('p.orden')->addOrderBy('p.nombre'),
                'placeholder' => 'Seleccione...',
            ])
            ->add('seccion', EntityType::class, [
                'label' => 'Sección',
                'class' => Seccion::class,
                'choices' => $this->seccionRepository->arbol(),
                'choice_label' => fn (Seccion $s) => $s->getRuta(),
                'group_by' => fn (Seccion $s) => $s->getProyecto()->getNombre(),
                'required' => false,
                'placeholder' => 'Sin sección',
            ])
            ->add('nombre', TextType::class, [
                'label' => 'Identificador',
                'required' => false,
                'help' => 'Minúsculas sin espacios, ej. recogida',
            ])
            ->add('modelo', TextType::class, [
                'label' => 'Modelo ERP',
                'required' => false,
                'help' => 'Entidad del ERP que abre esta ayuda, ej. TteGuia',
            ])
            ->add('video', UrlType::class, [
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('orden', IntegerType::class, ['empty_data' => '0'])
            ->add('publicado', CheckboxType::class, ['required' => false])
            ->add('contenido', TextareaType::class, [
                'required' => false,
                'empty_data' => '',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Documento::class,
        ]);
    }
}
