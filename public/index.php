<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use Respect\Validation\Validator as v;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Logger
$logger = new Logger('bunshop');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
$container->set('logger', $logger);

// DB (Eloquent)
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

// JSON helper
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware((bool)($_ENV['APP_DEBUG'] ?? 0), true, true);

// Health
$app->get('/health', fn($req,$res)=>$res->withJson(['ok'=>true]));

// List products
$app->get('/products', function($req,$res){
    $rows = Capsule::table('products')->where('is_active', true)->orderBy('id')->get();
    return $res->withJson($rows);
});

// List windows (available)
$app->get('/windows', function($req,$res){
    $productId = $req->getQueryParams()['product_id'] ?? null; // niewykorzystane w tym wariancie (global capacity)
    $rows = Capsule::select("
      SELECT pw.id, pw.date, pw.kind, GREATEST(v.remaining,0) AS remaining, pw.cutoff_at
      FROM pickup_windows pw
      JOIN vw_capacity v ON v.pickup_window_id = pw.id
      WHERE (pw.cutoff_at IS NULL OR pw.cutoff_at > NOW())
      ORDER BY pw.date ASC
    ");
    return $res->withJson($rows);
});

// Create order -> create Stripe Checkout Session
$app->post('/orders', function($req,$res) {
    $data = (array)$req->getParsedBody();

    $email = trim($data['email'] ?? '');
    $productId = (int)($data['product_id'] ?? 0);
    $qtyPacks  = (int)($data['qty_packs'] ?? 1);
    $windowId  = (int)($data['pickup_window_id'] ?? 0);
    $notes     = trim($data['notes'] ?? '');
    $address   = $data['shipping_address'] ?? null; // array lub null

    // Walidacja
    v::email()->assert($email);
    v::intVal()->positive()->assert($productId);
    v::intVal()->positive()->assert($qtyPacks);
    v::intVal()->positive()->assert($windowId);

    // Dane produktu i okna
    $product = Capsule::table('products')->where('id',$productId)->where('is_active',true)->first();
    if (!$product) { throw new \RuntimeException('Product not found'); }

    $window  = Capsule::table('pickup_windows')->where('id',$windowId)->first();
    if (!$window) { throw new \RuntimeException('Window not found'); }
    if ($window->cutoff_at !== null && strtotime($window->cutoff_at) <= time()) {
        throw new \RuntimeException('Cutoff passed');
    }

    // Wyliczenie zapotrzebowania w sztukach (global capacity)
    $needPieces = ($product->pack_size ?? 1) * $qtyPacks;

    // Transakcja + blokada (FOR UPDATE) na okno
    Capsule::connection()->transaction(function() use ($product,$window,$needPieces,$email,$notes,$address,$qtyPacks,&$orderId,&$sessionUrl,&$sessionId,&$paymentIntent) {

        // policz remaining z widoku w transakcji
        $row = Capsule::selectOne("SELECT GREATEST(v.remaining,0) AS remaining FROM vw_capacity v WHERE v.pickup_window_id = ?", [$window->id]);
        if ((int)$row->remaining < $needPieces) {
            throw new \RuntimeException('Not enough capacity');
        }

        // Utwórz order pending + items + TTL
        $ttlMin = (int)($_ENV['ORDER_PENDING_TTL_MIN'] ?? 20);
        $expiresAt = (new DateTimeImmutable())->modify("+{$ttlMin} minutes")->format('Y-m-d H:i:s');

        $orderId = Capsule::table('orders')->insertGetId([
            'status' => 'pending',
            'customer_email' => $email,
            'shipping_address_json' => $address ? json_encode($address, JSON_UNESCAPED_UNICODE) : null,
            'pickup_window_id' => $window->id,
            'notes' => $notes ?: null,
            'stripe_session_id' => null,
            'stripe_payment_intent' => null,
            'expires_at' => $expiresAt,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]);

        Capsule::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $product->id,
            'qty' => $qtyPacks,
            'unit_price' => $product->gross_price,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]);

        // Stripe Checkout (Payment Mode)
        \Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET']);

        $lineAmount = (int) round($product->gross_price * 100); // grosze
        $successUrl = str_replace('{ORDER_ID}', (string)$orderId, $_ENV['STRIPE_SUCCESS_URL']);
        $cancelUrl  = str_replace('{ORDER_ID}', (string)$orderId, $_ENV['STRIPE_CANCEL_URL']);

        $metadata = [
          'order_id' => (string)$orderId,
          'pickup_window_id' => (string)$window->id,
          'pickup_date' => $window->date,
          'kind' => $window->kind,
        ];

        $session = \Stripe\Checkout\Session::create([
          'mode' => 'payment',
          'success_url' => $successUrl,
          'cancel_url'  => $cancelUrl,
          'customer_email' => $email,
          'metadata' => $metadata,
          'line_items' => [[
            'price_data' => [
              'currency' => $_ENV['STRIPE_CURRENCY'] ?? 'pln',
              'product_data' => [
                'name' => $product->name . " (okno: {$window->date} / {$window->kind})",
              ],
              'unit_amount' => $lineAmount,
            ],
            'quantity' => $qtyPacks
          ]],
        ], [
          'idempotency_key' => "order_{$orderId}"
        ]);

        $sessionId    = $session->id;
        $paymentIntent = $session->payment_intent ?? null;
        $sessionUrl   = $session->url;

        Capsule::table('orders')->where('id',$orderId)->update([
          'stripe_session_id' => $sessionId,
          'stripe_payment_intent' => $paymentIntent,
          'updated_at' => date('c'),
        ]);
    });

    return $res->withJson([
      'order_id' => $orderId,
      'checkout_url' => $sessionUrl
    ], 201);
});

// Stripe webhook
$app->post('/webhooks/stripe', function($req,$res) {
    $payload = (string)$req->getBody();
    $sig = $req->getHeaderLine('Stripe-Signature');
    $secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
    } catch(\Throwable $e) {
        return $res->withStatus(400);
    }

    if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        $orderId = (int)($session->metadata->order_id ?? 0);
        if ($orderId > 0) {
            Capsule::table('orders')->where('id',$orderId)->update([
                'status' => 'paid',
                'stripe_payment_intent' => $session->payment_intent ?? null,
                'expires_at' => null,
                'updated_at' => date('c'),
            ]);
        }
    }

    if ($event->type === 'checkout.session.expired') {
        $session = $event->data->object;
        $orderId = (int)($session->metadata->order_id ?? 0);
        if ($orderId > 0) {
            // zwolnij rezerwację: ustaw canceled (albo usuń pending, jeśli wolisz)
            Capsule::table('orders')->where('id',$orderId)->where('status','pending')->update([
                'status' => 'canceled',
                'updated_at' => date('c'),
            ]);
        }
    }

    return $res->withStatus(200);
});

// Prosty status zamówienia
$app->get('/orders/{id}', function($req,$res,$args){
    $id = (int)$args['id'];
    $order = Capsule::table('orders')->where('id',$id)->first();
    if (!$order) return $res->withStatus(404);
    $items = Capsule::table('order_items')->where('order_id',$id)->get();
    return $res->withJson(['order'=>$order,'items'=>$items]);
});

// Success/Cancel (placeholder pod front)
$app->get('/success', fn($req,$res)=>$res->withJson(['ok'=>true]));
$app->get('/cancel',  fn($req,$res)=>$res->withJson(['ok'=>false]));

$app->run();

