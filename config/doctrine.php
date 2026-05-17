<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use DI\ContainerBuilder;

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Build container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/container.php');
$container = $containerBuilder->build();

// Get EntityManager
$entityManager = $container->get(EntityManager::class);

// Configure migrations
$config = new PhpFile(__DIR__ . '/migrations.php');

return DependencyFactory::fromEntityManager($config, new ExistingEntityManager($entityManager));

