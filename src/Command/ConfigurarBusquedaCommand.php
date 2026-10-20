<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prepara PostgreSQL para la búsqueda sin tildes de DocumentoRepository::buscar().
 *
 * El proyecto no usa migraciones y doctrine:schema:update solo crea tablas: no administra
 * extensiones ni funciones. Este comando se puede ejecutar las veces que se quiera.
 */
#[AsCommand(name: 'app:configurar-busqueda', description: 'Crea la extensión unaccent y la función f_unaccent que usa la búsqueda')]
class ConfigurarBusquedaCommand extends Command
{
    public function __construct(private readonly Connection $conexion)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->conexion->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->error('La búsqueda de la documentación necesita PostgreSQL.');

            return Command::FAILURE;
        }

        $this->conexion->executeStatement('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() no es IMMUTABLE; este envoltorio permite usarla en índices y lo hace más rápido.
        $this->conexion->executeStatement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);

        $io->success(sprintf('Búsqueda configurada: f_unaccent(\'Facturación\') = %s', $this->conexion->fetchOne("SELECT f_unaccent('Facturación')")));

        return Command::SUCCESS;
    }
}
