<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
  'driver' => 'pgsql',
  'host' => $_ENV['DB_HOST'],
  'database' => $_ENV['DB_DATABASE'],
  'username' => $_ENV['DB_USERNAME'],
  'password' => $_ENV['DB_PASSWORD'],
  'port' => $_ENV['DB_PORT'],
  'charset' => 'utf8',
  'prefix' => '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::table('products')->insert([
  ['name' => 'Bułki 10 szt.', 'pack_size' => 10, 'gross_price' => 19.90, 'is_active' => true, 'created_at'=>now(), 'updated_at'=>now()],
  ['name' => 'Bułki 30 szt.', 'pack_size' => 30, 'gross_price' => 54.90, 'is_active' => true, 'created_at'=>now(), 'updated_at'=>now()],
]);

$today = new DateTimeImmutable('today');
for ($i=1; $i<=14; $i++) {
  $d = $today->modify("+$i day");
  Capsule::table('pickup_windows')->insert([
    'date' => $d->format('Y-m-d'),
    'kind' => ($i % 2 === 0) ? 'shipping' : 'pickup',
    'capacity' => 2000, // sztuk dziennie (sumarycznie)
    'cutoff_at' => $d->setTime((int)($_ENV['DEFAULT_PICKUP_CUTOFF_HOUR'] ?? 10),0)->format('Y-m-d H:i:s'),
    'created_at'=>date('c'), 'updated_at'=>date('c')
  ]);
}

echo "Seed done\n";

