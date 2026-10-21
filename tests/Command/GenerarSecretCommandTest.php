<?php

namespace App\Tests\Command;

use App\Command\GenerarSecretCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Trabaja en una carpeta temporal: nunca toca el .env real del proyecto.
 */
class GenerarSecretCommandTest extends TestCase
{
    private string $directorio;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir().'/generar-secret-'.bin2hex(random_bytes(4));
        mkdir($this->directorio);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directorio.'/{,.}*', GLOB_BRACE | GLOB_NOSORT) ?: []);
        rmdir($this->directorio);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new GenerarSecretCommand($this->directorio));
    }

    private function env(string $contenido): string
    {
        file_put_contents($this->directorio.'/.env', $contenido);

        return $this->directorio.'/.env';
    }

    public function testSinOpcionesSoloMuestraUnSecretDe64CaracteresHexadecimales(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim($tester->getDisplay()));
        self::assertFileDoesNotExist($this->directorio.'/.env');
    }

    public function testCadaSecretEsDistinto(): void
    {
        $tester = $this->tester();
        $tester->execute([]);
        $primero = trim($tester->getDisplay());
        $tester->execute([]);

        self::assertNotSame($primero, trim($tester->getDisplay()));
    }

    public function testCompletaUnAppSecretVacioSinTocarLasDemasLineas(): void
    {
        $archivo = $this->env("APP_ENV=prod\nAPP_SECRET=\nDATABASE_URL=\"postgresql://x\"\n");

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--guardar' => true]));
        self::assertMatchesRegularExpression('/^APP_ENV=prod\nAPP_SECRET=[0-9a-f]{64}\nDATABASE_URL="postgresql:\/\/x"\n$/', file_get_contents($archivo));
    }

    public function testAgregaLaVariableSiNoExiste(): void
    {
        $archivo = $this->env("APP_ENV=prod\n");

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--guardar' => true]));
        self::assertMatchesRegularExpression('/^APP_ENV=prod\nAPP_SECRET=[0-9a-f]{64}\n$/', file_get_contents($archivo));
    }

    public function testNoReemplazaUnSecretExistenteSinForzar(): void
    {
        $archivo = $this->env("APP_SECRET=secreto-actual\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--guardar' => true]));
        self::assertSame("APP_SECRET=secreto-actual\n", file_get_contents($archivo));
        self::assertStringContainsString('--forzar', $tester->getDisplay());
    }

    public function testConForzarReemplazaElSecretExistente(): void
    {
        $archivo = $this->env("APP_SECRET=secreto-actual\n");

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--guardar' => true, '--forzar' => true]));
        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{64}\n$/', file_get_contents($archivo));
    }

    public function testAvisaSiHayEnvLocalPhpPorqueTieneLaPrioridad(): void
    {
        $this->env("APP_SECRET=\n");
        file_put_contents($this->directorio.'/.env.local.php', '<?php return [];');
        $tester = $this->tester();

        $tester->execute(['--guardar' => true]);

        self::assertStringContainsString('composer dump-env prod', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testFallaSiElArchivoNoExiste(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--guardar' => true, '--archivo' => '.env.prod']));
        self::assertStringContainsString('No existe el archivo', $tester->getDisplay());
    }
}
