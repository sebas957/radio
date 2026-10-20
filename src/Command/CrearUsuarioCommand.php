<?php

namespace App\Command;

use App\Entity\Usuario;
use App\Repository\UsuarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:crear-usuario', description: 'Crea un usuario para ingresar a la documentación')]
class CrearUsuarioCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Nombre de usuario')
            ->addArgument('nombre', InputArgument::REQUIRED, 'Nombre completo')
            ->addOption('editor', null, InputOption::VALUE_NONE, 'Asigna ROLE_EDITOR (puede editar la documentación)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Asigna ROLE_ADMIN (incluye ROLE_EDITOR)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        if ($this->usuarioRepository->findOneBy(['username' => $username])) {
            $io->error(sprintf('Ya existe el usuario "%s".', $username));

            return Command::FAILURE;
        }

        $clave = $io->askHidden('Contraseña', function (?string $valor) {
            if (null === $valor || mb_strlen($valor) < 8) {
                throw new \RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            }

            return $valor;
        });

        $usuario = (new Usuario())
            ->setUsername($username)
            ->setNombre($input->getArgument('nombre'))
            ->setRoles(match (true) {
                $input->getOption('admin') => ['ROLE_ADMIN'],
                $input->getOption('editor') => ['ROLE_EDITOR'],
                default => [],
            });
        $usuario->setPassword($this->passwordHasher->hashPassword($usuario, $clave));

        $this->em->persist($usuario);
        $this->em->flush();

        $io->success(sprintf('Usuario "%s" creado.', $username));

        return Command::SUCCESS;
    }
}
