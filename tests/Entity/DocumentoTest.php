<?php

namespace App\Tests\Entity;

use App\Entity\Documento;
use App\Entity\Proyecto;
use App\Entity\Seccion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DocumentoTest extends TestCase
{
    private ValidatorInterface $validador;

    protected function setUp(): void
    {
        $this->validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testRechazaUnaSeccionDeOtroProyecto(): void
    {
        $semantica = (new Proyecto())->setNombre('Semantica')->setSlug('semantica');
        $reddoc = (new Proyecto())->setNombre('RedDoc')->setSlug('reddoc');
        $logistica = (new Seccion())->setProyecto($semantica)->setNombre('logistica')->setTitulo('Logística');

        $documento = (new Documento())->setTitulo('Guía')->setProyecto($reddoc)->setSeccion($logistica);
        $errores = $this->validador->validate($documento);

        self::assertCount(1, $errores);
        self::assertSame('seccion', $errores[0]->getPropertyPath());
        self::assertSame('La sección no pertenece al proyecto seleccionado.', $errores[0]->getMessage());
    }

    public function testAceptaUnaSeccionDelMismoProyecto(): void
    {
        $semantica = (new Proyecto())->setNombre('Semantica')->setSlug('semantica');
        $logistica = (new Seccion())->setProyecto($semantica)->setNombre('logistica')->setTitulo('Logística');

        $documento = (new Documento())->setTitulo('Guía')->setProyecto($semantica)->setSeccion($logistica);

        self::assertCount(0, $this->validador->validate($documento));
    }

    public function testLaSeccionEsOpcional(): void
    {
        $semantica = (new Proyecto())->setNombre('Semantica')->setSlug('semantica');

        self::assertCount(0, $this->validador->validate((new Documento())->setTitulo('Guía')->setProyecto($semantica)));
    }
}
