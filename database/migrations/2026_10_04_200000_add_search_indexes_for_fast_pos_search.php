<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Indexes used by ProductUtil::filterProduct for fast POS search on large catalogs.
     * Building these on millions of rows can take several minutes; run off-hours.
     */
    private array $indexes = [
        'products' => [
            'products_biz_inactive_type_index' => 'INDEX products_biz_inactive_type_index (business_id, is_inactive, type)',
            'products_biz_sku_index' => 'INDEX products_biz_sku_index (business_id, sku)',
            'ft_products_name_sku' => 'FULLTEXT ft_products_name_sku (name, sku) WITH PARSER ngram',
        ],
        'variations' => [
            'variations_product_deleted_index' => 'INDEX variations_product_deleted_index (product_id, deleted_at)',
        ],
        'variation_location_details' => [
            'vld_variation_location_index' => 'INDEX vld_variation_location_index (variation_id, location_id)',
        ],
        'product_locations' => [
            'product_locations_location_product_index' => 'INDEX product_locations_location_product_index (location_id, product_id)',
        ],
    ];

    public function up(): void
    {
        // The ngram parser drops every token that contains a stopword ("a", "i", "in", ...),
        // which would hide most products. Build the fulltext index without stopwords.
        DB::statement('SET SESSION innodb_ft_enable_stopword = OFF');

        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $definition) {
                if (! $this->hasIndex($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` ADD {$definition}");
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->hasIndex($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return ! empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]));
    }
};
