<?php

namespace App\Tests\Command;

use App\Command\ConfigurarBusquedaCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigurarBusquedaCommandTest extends TestCase
{
    public function testCreaLaExtensionYLaFuncionSinFallarSiYaExisten(): void
    {
        $conexion = $this->createMock(Connection::class);
        $conexion->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $conexion->method('fetchOne')->willReturn('Facturacion');

        $sentencias = [];
        $conexion->expects(self::exactly(2))->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$sentencias) {
                $sentencias[] = $sql;

                return 0;
            });

        $tester = new CommandTester(new ConfigurarBusquedaCommand($conexion));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('CREATE EXTENSION IF NOT EXISTS unaccent', $sentencias[0]);
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION f_unaccent(text)', $sentencias[1]);
        self::assertStringContainsString('IMMUTABLE', $sentencias[1]);
    }

    public function testSoloFuncionaConPostgresql(): void
    {
        $conexion = $this->createMock(Connection::class);
        $conexion->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $conexion->expects(self::never())->method('executeStatement');

        $tester = new CommandTester(new ConfigurarBusquedaCommand($conexion));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('necesita PostgreSQL', $tester->getDisplay());
    }
}
