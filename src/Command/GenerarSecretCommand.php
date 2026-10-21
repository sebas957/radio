<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Genera el APP_SECRET del proyecto (64 caracteres hexadecimales, 256 bits aleatorios).
 *
 * Symfony lo usa para firmar la sesión "Mantenerme conectado", los tokens CSRF y los enlaces
 * firmados; cada entorno debe tener el suyo y nunca el de .env.dev, que es público en GitHub.
 */
#[AsCommand(name: 'app:generar-secret', description: 'Genera un APP_SECRET aleatorio y opcionalmente lo guarda en el .env')]
class GenerarSecretCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $directorioProyecto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('guardar', null, InputOption::VALUE_NONE, 'Escribe el secret en el archivo de entorno')
            ->addOption('archivo', null, InputOption::VALUE_REQUIRED, 'Archivo de entorno donde guardarlo', '.env')
            ->addOption('forzar', null, InputOption::VALUE_NONE, 'Reemplaza un APP_SECRET que ya tiene valor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $secret = bin2hex(random_bytes(32));

        if (!$input->getOption('guardar')) {
            $io->writeln($secret);

            return Command::SUCCESS;
        }

        $archivo = $this->ruta($input->getOption('archivo'));
        if (!is_file($archivo)) {
            $io->error(sprintf('No existe el archivo "%s".', $archivo));

            return Command::FAILURE;
        }

        $contenido = (string) file_get_contents($archivo);
        $patron = '/^APP_SECRET=(.*)$/m';
        $actual = preg_match($patron, $contenido, $coincidencia) ? trim($coincidencia[1], " \t\"'") : null;

        if ('' !== (string) $actual && !$input->getOption('forzar')) {
            $io->error([
                sprintf('%s ya tiene un APP_SECRET.', $archivo),
                'Cambiarlo cierra todas las sesiones y anula los "Mantenerme conectado". Use --forzar si es lo que quiere.',
            ]);

            return Command::FAILURE;
        }

        $linea = 'APP_SECRET='.$secret;
        $contenido = null !== $actual
            ? preg_replace($patron, $linea, $contenido, 1)
            : rtrim($contenido, "\n")."\n".$linea."\n";
        file_put_contents($archivo, $contenido);

        $io->success(sprintf('APP_SECRET guardado en %s.', $archivo));

        // Con .env.local.php (composer dump-env) Symfony ya no lee el .env.
        if (is_file($this->directorioProyecto.'/.env.local.php')) {
            $io->warning('Existe .env.local.php: ejecute "composer dump-env prod" para que el cambio tenga efecto.');
        }

        return Command::SUCCESS;
    }

    private function ruta(string $archivo): string
    {
        return str_starts_with($archivo, '/') ? $archivo : $this->directorioProyecto.'/'.$archivo;
    }
}
