<?php
require __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

return [
  'paths' => [
    'migrations' => 'migrations',
  ],
  'environments' => [
    'default_migration_table' => 'migrations_log',
    'default_environment' => 'docker',
    'docker' => [
      'adapter' => 'pgsql',
      'host' => $_ENV['DB_HOST'] ?? 'db',
      'name' => $_ENV['DB_DATABASE'] ?? 'bunshop',
      'user' => $_ENV['DB_USERNAME'] ?? 'bunshop',
      'pass' => $_ENV['DB_PASSWORD'] ?? 'secret',
      'port' => $_ENV['DB_PORT'] ?? 5432,
      'charset' => 'utf8'
    ],
  ],
];

