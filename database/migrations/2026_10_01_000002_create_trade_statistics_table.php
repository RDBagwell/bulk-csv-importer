<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rows written by TradeStatisticsDefinition.
     *
     * The primary key (import_id, line_number) is what makes a retried chunk
     * idempotent: re-inserting a row hits the key and is skipped. Being the
     * clustered index, it also keeps each worker's inserts appending within
     * its own key range instead of adding a separate surrogate key.
     *
     * There is deliberately no foreign key to imports: InnoDB would look up
     * and share-lock the parent row for every inserted row, and the error
     * recorder takes an exclusive lock on that row, so parallel workers would
     * serialise on it. No secondary indexes either: nothing in the app
     * queries this table, and every index slows bulk inserts. Add indexes for
     * your reporting queries after loading.
     */
    public function up(): void
    {
        Schema::create('trade_statistics', function (Blueprint $table) {
            $table->unsignedBigInteger('import_id');
            $table->unsignedBigInteger('line_number');
            $table->char('time_ref', 6);
            $table->string('account', 16);
            $table->string('code', 10);
            $table->char('country_code', 2);
            $table->string('product_type', 16);
            $table->decimal('value', 18, 2);
            $table->char('status', 1);

            $table->primary(['import_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_statistics');
    }
};
