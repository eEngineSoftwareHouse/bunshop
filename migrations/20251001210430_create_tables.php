<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateTables extends AbstractMigration
{
    public function change(): void
    {
        // products
        $this->table('products')
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('pack_size', 'integer', ['null' => true])
            ->addColumn('gross_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->create();

        // pickup_windows (kind: pickup|shipping)
        $this->table('pickup_windows')
            ->addColumn('date', 'date')
            ->addColumn('kind', 'string', ['limit' => 16]) // zamiast enum
            ->addColumn('capacity', 'integer')             // limit w sztukach (sumarycznie)
            ->addColumn('cutoff_at', 'datetime', ['null' => true])
            ->addIndex(['date','kind'], ['unique' => true])
            ->addTimestamps()
            ->create();

        // orders (status: pending|paid|canceled)
        $this->table('orders')
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending'])
            ->addColumn('customer_email', 'string', ['limit' => 190])
            ->addColumn('shipping_address_json', 'text', ['null' => true])
            ->addColumn('pickup_window_id', 'integer')
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('stripe_session_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('stripe_payment_intent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['pickup_window_id'])
            ->create();

        // order_items
        $this->table('order_items')
            ->addColumn('order_id', 'integer')
            ->addColumn('product_id', 'integer')
            ->addColumn('qty', 'integer')
            ->addColumn('unit_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addTimestamps()
            ->addIndex(['order_id'])
            ->addIndex(['product_id'])
            ->create();

        // CHECK constraints (enum-like)
        $this->execute("ALTER TABLE pickup_windows ADD CONSTRAINT chk_pickup_windows_kind CHECK (kind IN ('pickup','shipping'));");
        $this->execute("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('pending','paid','canceled'));");

        // (opcjonalnie FK – bez ON DELETE CASCADE, żeby prosto)
        $this->execute("ALTER TABLE orders      ADD CONSTRAINT fk_orders_pickup_window  FOREIGN KEY (pickup_window_id) REFERENCES pickup_windows(id);");
        $this->execute("ALTER TABLE order_items ADD CONSTRAINT fk_items_order           FOREIGN KEY (order_id)        REFERENCES orders(id);");
        $this->execute("ALTER TABLE order_items ADD CONSTRAINT fk_items_product         FOREIGN KEY (product_id)      REFERENCES products(id);");

        // Widok dostępności (global capacity)
        $this->execute("
          CREATE OR REPLACE VIEW vw_capacity AS
          SELECT
            pw.id AS pickup_window_id,
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
