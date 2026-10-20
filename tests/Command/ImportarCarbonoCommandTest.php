<?php

namespace App\Tests\Command;

use App\Command\ImportarCarbonoCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Protección de --reemplazar: nunca borra sin confirmación. No toca ninguna base real;
 * la conexión de radio es un mock que falla la prueba si se ejecuta un DELETE.
 */
class ImportarCarbonoCommandTest extends TestCase
{
    // Puerto cerrado: si el comando pasa la confirmación, falla al conectar con carbono.
    private const ORIGEN = 'mysql://usuario:clave@127.0.0.1:1/bdcarbono';

    private Connection&MockObject $conexion;

    protected function setUp(): void
    {
        $this->conexion = $this->createMock(Connection::class);
    }

    private function tester(int $existentes, int $editados): CommandTester
    {
        $this->conexion->method('fetchNumeric')->willReturn([$existentes, $editados]);

        $comando = new ImportarCarbonoCommand(
            $this->conexion,
            $this->createStub(HttpClientInterface::class),
            dirname(__DIR__, 2).'/data/importacion/teneso-menu.json',
        );

        return new CommandTester($comando);
    }

    private function noDebeBorrarNada(): void
    {
        $this->conexion->expects(self::never())->method('executeStatement');
        $this->conexion->expects(self::never())->method('beginTransaction');
    }

    public function testSinReemplazarNoTocaDocumentosExistentes(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 0);

        self::assertSame(Command::FAILURE, $tester->execute(['--origen' => self::ORIGEN]));
        self::assertStringContainsString('Ya hay 436 documentos', $tester->getDisplay());
    }

    public function testAvisaCuantosDocumentosEditadosSePerderianYCancelaSiNoSeConfirma(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 12);
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute(['--origen' => self::ORIGEN, '--reemplazar' => true]));
        $salida = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Se borrarán 436 documentos', $salida);
        self::assertStringContainsString('12 documentos fueron editados en radio', $salida);
        self::assertStringContainsString('no se borró nada', $salida);
    }

    public function testLaRespuestaPorDefectoEsNo(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 0);
        $tester->setInputs(['']);

        self::assertSame(Command::FAILURE, $tester->execute(['--origen' => self::ORIGEN, '--reemplazar' => true]));
    }

    public function testEnModoNoInteractivoExigeForzar(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 0);

        self::assertSame(Command::FAILURE, $tester->execute(
            ['--origen' => self::ORIGEN, '--reemplazar' => true],
            ['interactive' => false],
        ));
        self::assertStringContainsString('agregue --forzar', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function respuestasAfirmativas(): iterable
    {
        yield 'si' => ['si'];
        yield 's' => ['s'];
        yield 'Sí con tilde' => ['Sí'];
        yield 'yes' => ['yes'];
    }

    #[DataProvider('respuestasAfirmativas')]
    public function testConfirmandoContinuaConLaImportacion(string $respuesta): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 0);
        $tester->setInputs([$respuesta]);

        // Llegar a conectar con carbono demuestra que pasó la confirmación; el borrado
        // ocurre después, dentro de la transacción, cuando ya se leyeron los datos de origen.
        $this->expectException(DbalException::class);
        $tester->execute(['--origen' => self::ORIGEN, '--reemplazar' => true]);
    }

    public function testConForzarNoPreguntaNiEnModoNoInteractivo(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(436, 0);

        $this->expectException(DbalException::class);
        $tester->execute(['--origen' => self::ORIGEN, '--reemplazar' => true, '--forzar' => true], ['interactive' => false]);
    }

    public function testSinDocumentosNoPideConfirmacion(): void
    {
        $this->noDebeBorrarNada();
        $tester = $this->tester(0, 0);

        $this->expectException(DbalException::class);
        $tester->execute(['--origen' => self::ORIGEN], ['interactive' => false]);
    }
}
