<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTables extends AbstractMigration
{
    public function change(): void
    {
        // products (np. "10 szt.", "30 szt.")
        $this->table('products')
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('pack_size', 'integer', ['null' => true])
            ->addColumn('gross_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->create();

        // pickup/shipping windows (data + typ + limit)
        $this->table('pickup_windows')
            ->addColumn('date', 'date')
            ->addColumn('kind', 'enum', ['values' => ['pickup', 'shipping']])
            ->addColumn('capacity', 'integer') // łączna dostępna liczba sztuk (albo paczek)
            ->addColumn('cutoff_at', 'datetime', ['null' => true]) // jeśli null, liczony z DEFAULT_PICKUP_CUTOFF_HOUR
            ->addIndex(['date','kind'], ['unique' => true])
            ->addTimestamps()
            ->create();

        // jeśli chcesz limit per produkt na daną datę — odkomentuj i używaj zamiast capacity globalnego
        // $this->table('inventory')
        //     ->addColumn('pickup_window_id', 'integer')
        //     ->addColumn('product_id', 'integer')
        //     ->addColumn('capacity', 'integer')
        //     ->addIndex(['pickup_window_id','product_id'], ['unique' => true])
        //     ->create();

        $this->table('orders')
            ->addColumn('status', 'enum', ['values' => ['pending','paid','canceled'], 'default' => 'pending'])
            ->addColumn('customer_email', 'string', ['limit' => 190])
            ->addColumn('shipping_address_json', 'text', ['null' => true]) // JSON dla wysyłki
            ->addColumn('pickup_window_id', 'integer')
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('stripe_session_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('stripe_payment_intent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->create();

        $this->table('order_items')
            ->addColumn('order_id', 'integer')
            ->addColumn('product_id', 'integer')
            ->addColumn('qty', 'integer')
            ->addColumn('unit_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addTimestamps()
            ->create();

        // prosty widok dostępności (dla global capacity)
        $this->execute("
          CREATE VIEW vw_capacity AS
          SELECT
            pw.id as pickup_window_id,
            pw.capacity
              - COALESCE((
                  SELECT SUM(oi.qty * COALESCE(p.pack_size, 1))
                  FROM orders o
                  JOIN order_items oi ON oi.order_id = o.id
                  JOIN products p ON p.id = oi.product_id
                  WHERE o.pickup_window_id = pw.id
                  AND o.status IN ('pending','paid')
                  AND (o.expires_at IS NULL OR o.expires_at > NOW())
                ), 0) AS remaining
          FROM pickup_windows pw;
        ");
    }
}

