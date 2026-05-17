<?php

declare(strict_types=1);

namespace App\Support\Console;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Comparator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrations:migrate';
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this->setDescription('Run database migrations from database/migrations folder');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show SQL without executing');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Execute all pending migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            /** @var EntityManager $em */
            $em = $this->container->get(EntityManager::class);
            
            // Doctrine Migrations yapılandırması
            // __DIR__ = app/Support/Console/, config = root/config/
            $possiblePaths = [
                __DIR__ . '/../../../config/migrations.php',  // app/Support/Console -> root/config/
                __DIR__ . '/../../config/migrations.php',     // Alternatif (app/config/ için)
                realpath(__DIR__ . '/../../../config/migrations.php'),
            ];
            
            $configPath = null;
            foreach ($possiblePaths as $path) {
                $realPath = realpath($path);
                if ($realPath && file_exists($realPath)) {
                    $configPath = $realPath;
                    break;
                }
            }
            
            if (!$configPath) {
                // Son deneme: Project root'tan dene
                $projectRoot = realpath(__DIR__ . '/../../..');
                if ($projectRoot) {
                    $rootConfigPath = $projectRoot . '/config/migrations.php';
                    if (file_exists($rootConfigPath)) {
                        $configPath = realpath($rootConfigPath);
                    }
                }
            }
            
            if (!$configPath) {
                $io->error('Migrations configuration file not found!');
                $io->text('Searched paths:');
                foreach ($possiblePaths as $path) {
                    $io->text('  - ' . $path);
                }
                return Command::FAILURE;
            }
            
            $io->text('Using config: ' . $configPath);
            $config = new PhpFile($configPath);
            $dependencyFactory = DependencyFactory::fromEntityManager($config, new ExistingEntityManager($em));
            
            // Metadata storage tablosunu oluştur (eğer yoksa)
            try {
                $metadataStorage = $dependencyFactory->getMetadataStorage();
                $metadataStorage->ensureInitialized();
            } catch (\Exception $e) {
                // Metadata storage zaten var veya başka bir sorun var
                $io->note('Metadata storage check: ' . $e->getMessage());
            }
            
            $io->title('Database Migration');
            
            // Migration'ları listele
            $migrationsRepository = $dependencyFactory->getMigrationRepository();
            $availableMigrations = $migrationsRepository->getMigrations();
            
            if (count($availableMigrations) === 0) {
                $io->warning('No migrations found in database/migrations folder!');
                return Command::SUCCESS;
            }
            
            $io->text('Available migrations:');
            foreach ($availableMigrations->getItems() as $migration) {
                $io->text('  - ' . $migration->getVersion());
            }
            
            // Çalıştırılmış migration'ları al
            $metadataStorage = $dependencyFactory->getMetadataStorage();
            $executedMigrationsList = $metadataStorage->getExecutedMigrations();
            $executedVersions = [];
            foreach ($executedMigrationsList as $executed) {
                $executedVersions[] = (string)$executed->getVersion();
            }
            
            // Çalıştırılmamış migration'ları bul
            $pendingMigrations = [];
            foreach ($availableMigrations->getItems() as $migration) {
                $version = (string)$migration->getVersion();
                if (!in_array($version, $executedVersions)) {
                    $pendingMigrations[] = $migration;
                }
            }
            
            if (empty($pendingMigrations)) {
                $io->success('All migrations are already executed!');
                return Command::SUCCESS;
            }
            
            $io->section('Pending migrations:');
            foreach ($pendingMigrations as $migration) {
                $io->text('  - ' . $migration->getVersion());
            }
            
            if ($input->getOption('dry-run')) {
                $io->note('Dry-run mode: No changes will be made');
                return Command::SUCCESS;
            }
            
            // Migration planı oluştur ve çalıştır
            $migrationPlanCalculator = $dependencyFactory->getMigrationPlanCalculator();
            $migrator = $dependencyFactory->getMigrator();
            $versionAliasResolver = $dependencyFactory->getVersionAliasResolver();
            
            $io->section('Executing migrations...');
            
            // 'latest' alias'ını resolve et (en son migration version'ı)
            try {
                $latestVersion = $versionAliasResolver->resolveVersionAlias('latest');
            } catch (\Exception $e) {
                // En son migration version'ını manuel bul
                $latestVersion = null;
                foreach ($availableMigrations->getItems() as $migration) {
                    $latestVersion = $migration->getVersion();
                }
            }
            
            if (!$latestVersion) {
                $io->warning('No migrations to execute!');
                return Command::SUCCESS;
            }
            
            // En son version'a kadar plan oluştur (tüm pending migration'lar dahil)
            $plan = $migrationPlanCalculator->getPlanUntilVersion($latestVersion);
            
            if (count($plan) === 0) {
                $io->success('All migrations are already executed!');
                return Command::SUCCESS;
            }
            
            // Plan'daki migration'ları göster
            foreach ($plan as $planItem) {
                $version = (string)$planItem->getVersion();
                $direction = $planItem->getDirection();
                $io->text('  - ' . $version . ' (' . $direction . ')');
            }
            
            // Migrator configuration oluştur (dry-run kontrolü için)
            $migratorConfiguration = new \Doctrine\Migrations\MigratorConfiguration();
            if ($input->getOption('dry-run')) {
                $migratorConfiguration->setDryRun(true);
            }
            
            // Tüm migration'ları tek seferde çalıştır
            $migrator->migrate($plan, $migratorConfiguration);
            
            $io->success('All migrations executed successfully!');
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            $io->text('File: ' . $e->getFile());
            $io->text('Line: ' . $e->getLine());
            return Command::FAILURE;
        }
    }
}

