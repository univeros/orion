<?php

declare(strict_types=1);

namespace App\Migrations;

use Cycle\Migrations\Migration;

/**
 * The application's own entity table; Orion's tables come from the module's migration.
 */
final class CreateProducts extends Migration
{
    protected const string DATABASE = 'default';

    public function up(): void
    {
        $this->table('products')
            ->addColumn('id', 'string', ['size' => 64])
            ->addColumn('title', 'string', ['size' => 255])
            ->addColumn('sku', 'string', ['size' => 64])
            ->setPrimaryKeys(['id'])
            ->create();
    }

    public function down(): void
    {
        $this->table('products')->drop();
    }
}
