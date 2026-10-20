<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Migra la documentación de carbono (tabla documentacion y proyecto) y el árbol de navegación
 * del menú de teneso (data/importacion/teneso-menu.json) a las tablas de este proyecto.
 *
 * El contenido de cada documento se toma, en este orden, de:
 *   1. --directorio: archivos {id}.md (copia de /documentacion del servidor de carbono)
 *   2. --api: endpoint /api/documentacion/archivo de carbono
 *   3. la columna documentacion.contenido (HTML antiguo), convertida a Markdown
 */
#[AsCommand(name: 'app:importar-carbono', description: 'Importa proyectos, secciones y documentos desde carbono y el menú de teneso')]
class ImportarCarbonoCommand extends Command
{
    public function __construct(
        private readonly Connection $conexion,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%/data/importacion/teneso-menu.json')]
        private readonly string $menuPorDefecto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('origen', null, InputOption::VALUE_REQUIRED, 'URL de la base de datos de carbono (por defecto CARBONO_DATABASE_URL)')
            ->addOption('menu', null, InputOption::VALUE_REQUIRED, 'JSON con el menú de teneso', $this->menuPorDefecto)
            ->addOption('proyecto-erp', null, InputOption::VALUE_REQUIRED, 'Id del proyecto al que pertenece el menú de teneso y los documentos sin proyecto', '3')
            ->addOption('directorio', null, InputOption::VALUE_REQUIRED, 'Carpeta con los archivos {id}.md')
            ->addOption('api', null, InputOption::VALUE_REQUIRED, 'URL de /api/documentacion/archivo de carbono para descargar los .md')
            ->addOption('reemplazar', null, InputOption::VALUE_NONE, 'Borra proyectos, secciones y documentos existentes antes de importar (pide confirmación)')
            ->addOption('forzar', null, InputOption::VALUE_NONE, 'Con --reemplazar, no pide confirmación (necesario en modo no interactivo)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $url = $input->getOption('origen') ?: ($_SERVER['CARBONO_DATABASE_URL'] ?? $_ENV['CARBONO_DATABASE_URL'] ?? null);
        if (!$url) {
            $io->error('Indique la base de datos de carbono con --origen o la variable CARBONO_DATABASE_URL.');

            return Command::FAILURE;
        }

        $menu = json_decode((string) @file_get_contents($input->getOption('menu')), true);
        if (!is_array($menu)) {
            $io->error(sprintf('No se pudo leer el menú "%s".', $input->getOption('menu')));

            return Command::FAILURE;
        }

        $directorio = $input->getOption('directorio');
        if ($directorio && !is_dir($directorio)) {
            $io->error(sprintf('No existe la carpeta "%s".', $directorio));

            return Command::FAILURE;
        }

        // actualizado_por solo se llena al guardar desde el administrador: son cambios hechos en radio.
        [$existentes, $editados] = array_map(intval(...), $this->conexion->fetchNumeric(
            'SELECT COUNT(*), COUNT(actualizado_por_id) FROM documento'
        ));
        if ($existentes > 0 && !$input->getOption('reemplazar')) {
            $io->error(sprintf('Ya hay %d documentos. Use --reemplazar para borrarlos e importar de nuevo.', $existentes));

            return Command::FAILURE;
        }
        if ($existentes > 0 && !$this->confirmarReemplazo($input, $io, $existentes, $editados)) {
            return Command::FAILURE;
        }

        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']);
        $carbono = DriverManager::getConnection($parser->parse($url));
        $proyectoErp = (int) $input->getOption('proyecto-erp');

        $proyectos = $carbono->fetchAllAssociative('SELECT codigo_proyecto_pk, nombre, orden, estado_activo FROM proyecto ORDER BY codigo_proyecto_pk');
        if (!in_array($proyectoErp, array_map(intval(...), array_column($proyectos, 'codigo_proyecto_pk')), true)) {
            $io->error(sprintf('El proyecto %d no existe en carbono.', $proyectoErp));

            return Command::FAILURE;
        }
        $documentaciones = $carbono->fetchAllAssociative('SELECT * FROM documentacion ORDER BY codigo_documentacion_pk');

        $this->conexion->beginTransaction();
        try {
            if ($input->getOption('reemplazar')) {
                $this->conexion->executeStatement('DELETE FROM documento');
                $this->conexion->executeStatement('DELETE FROM seccion');
                $this->conexion->executeStatement('DELETE FROM proyecto');
            }

            $this->importarProyectos($proyectos);
            [$ubicaciones, $avisosMenu] = $this->importarMenu($menu, $proyectoErp);
            $resumen = $this->importarDocumentos($io, $documentaciones, $ubicaciones, $proyectoErp, $directorio, $input->getOption('api'));
            $this->reiniciarSecuencias();

            $this->conexion->commit();
        } catch (\Throwable $e) {
            $this->conexion->rollBack();
            throw $e;
        }

        $io->success('Importación terminada.');
        $io->definitionList(
            ['Proyectos' => count($proyectos)],
            ['Secciones' => (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM seccion')],
            ['Documentos' => count($documentaciones)],
            ['  contenido desde archivo .md' => $resumen['archivo']],
            ['  contenido desde la API' => $resumen['api']],
            ['  contenido HTML convertido' => $resumen['html']],
            ['  sin contenido' => $resumen['vacio']],
            ['  sin sección (no están en el menú)' => $resumen['sinSeccion']],
        );
        foreach (array_merge($avisosMenu, $resumen['avisos']) as $aviso) {
            $io->warning($aviso);
        }

        return Command::SUCCESS;
    }

    /**
     * --reemplazar borra todo, incluidos los documentos editados en radio: se avisa cuántos son
     * y se pide confirmación. Sin terminal (cron, scripts) solo continúa con --forzar.
     */
    private function confirmarReemplazo(InputInterface $input, SymfonyStyle $io, int $existentes, int $editados): bool
    {
        $aviso = sprintf('Se borrarán %d documentos, todas las secciones y los proyectos.', $existentes);
        if ($editados > 0) {
            $aviso .= sprintf(' %d documentos fueron editados en radio y esos cambios se perderán.', $editados);
        }
        $io->warning($aviso);

        if ($input->getOption('forzar')) {
            return true;
        }
        if (!$input->isInteractive()) {
            $io->error('En modo no interactivo agregue --forzar para confirmar el reemplazo.');

            return false;
        }
        // La pregunta por defecto de Symfony solo acepta "y/yes"; aquí también "s/si".
        if (!$io->askQuestion(new ConfirmationQuestion('¿Borrar e importar de nuevo? (s/N)', false, '/^(s|y)/i'))) {
            $io->note('Importación cancelada; no se borró nada.');

            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $proyectos
     */
    private function importarProyectos(array $proyectos): void
    {
        $slugger = new AsciiSlugger('es');
        $usados = [];
        foreach ($proyectos as $proyecto) {
            $nombre = trim((string) $proyecto['nombre']) ?: 'Proyecto '.$proyecto['codigo_proyecto_pk'];
            $slug = $slugger->slug($nombre)->lower()->toString();
            if (isset($usados[$slug])) {
                $slug .= '-'.$proyecto['codigo_proyecto_pk'];
            }
            $usados[$slug] = true;

            $this->conexion->insert('proyecto', [
                'id' => (int) $proyecto['codigo_proyecto_pk'],
                'nombre' => $nombre,
                'slug' => $slug,
                'orden' => (int) $proyecto['orden'],
                'activo' => (bool) $proyecto['estado_activo'],
            ], ['activo' => 'boolean']);
        }
    }

    /**
     * Crea el árbol aplicación > menú > grupo y devuelve dónde quedó cada documento del menú.
     *
     * @return array{0: array<int, array{seccion: int, nombre: string, titulo: string, orden: int}>, 1: list<string>}
     */
    private function importarMenu(array $menu, int $proyectoId): array
    {
        $ubicaciones = [];
        $avisos = [];
        $sinDocumento = 0;

        foreach ($menu as $ordenAplicacion => $aplicacion) {
            $aplicacionId = $this->crearSeccion($proyectoId, null, $aplicacion['aplicacion'], $aplicacion['nombre'], $ordenAplicacion);
            foreach ($aplicacion['menu'] as $ordenMenu => $itemMenu) {
                $menuId = $this->crearSeccion($proyectoId, $aplicacionId, $itemMenu['nombre'], $this->titulo($itemMenu['nombre']), $ordenMenu);
                foreach ($itemMenu['grupos'] as $ordenGrupo => $grupo) {
                    $grupoId = $this->crearSeccion($proyectoId, $menuId, $grupo['nombre'], $this->titulo($grupo['titulo']), $ordenGrupo);
                    foreach ($grupo['archivos'] as $ordenArchivo => $archivo) {
                        if (!isset($archivo['id'])) {
                            ++$sinDocumento;
                            continue;
                        }
                        if (isset($ubicaciones[$archivo['id']])) {
                            $avisos[] = sprintf('El documento %d aparece varias veces en el menú; se dejó en la primera ubicación (%s).', $archivo['id'], $ubicaciones[$archivo['id']]['nombre']);
                            continue;
                        }
                        $ubicaciones[$archivo['id']] = [
                            'seccion' => $grupoId,
                            'nombre' => $archivo['nombre'],
                            'titulo' => $archivo['titulo'],
                            'orden' => $ordenArchivo,
                        ];
                    }
                }
            }
        }

        if ($sinDocumento > 0) {
            $avisos[] = sprintf('%d opciones del menú de teneso no tienen documento (sin id) y no se importaron.', $sinDocumento);
        }

        return [$ubicaciones, $avisos];
    }

    private function crearSeccion(int $proyectoId, ?int $padreId, string $nombre, string $titulo, int $orden): int
    {
        $this->conexion->insert('seccion', [
            'proyecto_id' => $proyectoId,
            'padre_id' => $padreId,
            'nombre' => $nombre,
            'titulo' => $titulo,
            'orden' => $orden,
        ]);

        return (int) $this->conexion->lastInsertId();
    }

    /**
     * @return array{archivo: int, api: int, html: int, vacio: int, sinSeccion: int, avisos: list<string>}
     */
    private function importarDocumentos(SymfonyStyle $io, array $documentaciones, array $ubicaciones, int $proyectoErp, ?string $directorio, ?string $api): array
    {
        $resumen = ['archivo' => 0, 'api' => 0, 'html' => 0, 'vacio' => 0, 'sinSeccion' => 0, 'avisos' => []];
        $convertidor = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style',
            'hard_break' => true,
            'header_style' => 'atx',
        ]);
        $importados = [];

        $io->progressStart(count($documentaciones));
        foreach ($documentaciones as $fila) {
            $id = (int) $fila['codigo_documentacion_pk'];
            $ubicacion = $ubicaciones[$id] ?? null;

            [$contenido, $origen] = $this->contenido($id, $fila['contenido'], $directorio, $api, $convertidor);
            ++$resumen[$origen];
            if (!$ubicacion) {
                ++$resumen['sinSeccion'];
            }

            $this->conexion->insert('documento', [
                'id' => $id,
                // Los documentos del menú son del ERP aunque en carbono tengan otro proyecto.
                'proyecto_id' => $ubicacion ? $proyectoErp : ((int) $fila['codigo_proyecto_fk'] ?: $proyectoErp),
                'seccion_id' => $ubicacion['seccion'] ?? null,
                'titulo' => trim((string) $fila['titulo']) ?: ($ubicacion['titulo'] ?? 'Documento '.$id),
                'nombre' => $ubicacion['nombre'] ?? null,
                'contenido' => $contenido,
                'modelo' => $fila['codigo_modelo_fk'] ?: null,
                'video' => $fila['video'] ?: null,
                'orden' => $ubicacion['orden'] ?? (int) $fila['orden'],
                'publicado' => true,
                'fecha_actualizacion' => $fila['fecha_actualizacion'] ? $fila['fecha_actualizacion'].' 00:00:00' : date('Y-m-d H:i:s'),
            ], ['publicado' => 'boolean']);
            $importados[$id] = true;
            $io->progressAdvance();
        }
        $io->progressFinish();

        $faltantes = array_diff_key($ubicaciones, $importados);
        if ($faltantes) {
            $resumen['avisos'][] = sprintf('El menú de teneso enlaza documentos que no existen en carbono: %s.', implode(', ', array_keys($faltantes)));
        }

        return $resumen;
    }

    /**
     * @return array{0: string, 1: 'archivo'|'api'|'html'|'vacio'}
     */
    private function contenido(int $id, ?string $html, ?string $directorio, ?string $api, HtmlConverter $convertidor): array
    {
        if ($directorio && is_file($archivo = rtrim($directorio, '/')."/{$id}.md")) {
            return [(string) file_get_contents($archivo), 'archivo'];
        }

        if ($api) {
            $respuesta = $this->httpClient->request('POST', $api, ['json' => ['id' => $id]])->toArray(false);
            if (false === ($respuesta['error'] ?? true) && '' !== trim((string) ($respuesta['archivo'] ?? ''))) {
                return [$respuesta['archivo'], 'api'];
            }
        }

        $html = trim((string) $html);
        if ('' === $html) {
            return ['', 'vacio'];
        }

        return [trim($convertidor->convert(str_replace('&nbsp;', ' ', $html))), 'html'];
    }

    /**
     * En PostgreSQL las columnas identity no avanzan solas cuando se insertan ids explícitos.
     */
    private function reiniciarSecuencias(): void
    {
        if (!$this->conexion->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        foreach (['proyecto', 'seccion', 'documento'] as $tabla) {
            $this->conexion->executeQuery(sprintf(
                "SELECT setval(pg_get_serial_sequence('%1\$s', 'id'), COALESCE((SELECT MAX(id) FROM %1\$s), 0) + 1, false)",
                $tabla,
            ));
        }
    }

    /**
     * Los menús de teneso solo traen el nombre en minúsculas y sin tildes, y algunos grupos
     * tienen el título en minúscula.
     */
    private function titulo(string $nombre): string
    {
        return match ($nombre) {
            'administracion' => 'Administración',
            default => mb_strtoupper(mb_substr($nombre, 0, 1)).mb_substr($nombre, 1),
        };
    }
}
