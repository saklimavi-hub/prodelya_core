<?php

namespace App\Console\Commands;

use App\Services\Stock\LocalStockExactVariantCorrectionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RepairLocalStockVariantsCommand extends Command
{
    protected $signature = 'prodelya:repair-local-stock-variants
        {--tenant= : Tenant account ID}
        {--product= : Tenant catalog product ID}
        {--legacy-stock= : Legacy tenant_local_stocks row ID}
        {--map=* : variant_id:quantity mapping}
        {--dry-run : Write-free plan output}
        {--apply : Apply correction in a transaction}
        {--actor= : Optional actor user ID}';

    protected $description = 'Controlled exact variant local stock correction for explicitly mapped legacy rows.';

    public function handle(LocalStockExactVariantCorrectionService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('--dry-run ve --apply birlikte kullanilamaz.');

            return self::FAILURE;
        }

        $mode = $this->option('apply') ? 'apply' : 'dry-run';

        try {
            $payload = [
                'tenant_id' => (int) $this->option('tenant'),
                'product_id' => (int) $this->option('product'),
                'legacy_stock_id' => (int) $this->option('legacy-stock'),
                'maps' => $this->option('map') ?: [],
                'actor_id' => $this->option('actor'),
                'evidence_ids' => [1, 2],
            ];

            $result = $mode === 'apply'
                ? $service->apply($payload)
                : $service->dryRun($payload);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Correction failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Status: ' . ($result['status'] ?? 'unknown'));
        $this->line('Message: ' . ($result['message'] ?? '-'));
        $this->line('Writes: ' . (int) ($result['writes'] ?? 0));
        $this->line('');

        if (! empty($result['legacy_row'])) {
            $legacy = $result['legacy_row'];
            $this->info('Legacy');
            $this->line(sprintf(
                'row %d | scope=%s | variant_id=%s | on_hand=%s | reserved=%s | available=%s | status=%s',
                $legacy['id'],
                $legacy['stock_scope'] ?? '-',
                $legacy['tenant_catalog_product_variant_id'] ?? 'null',
                number_format((float) ($legacy['quantity_on_hand'] ?? 0), 4, '.', ''),
                number_format((float) ($legacy['quantity_reserved'] ?? 0), 4, '.', ''),
                number_format((float) ($legacy['quantity_available'] ?? 0), 4, '.', ''),
                $legacy['legacy_assignment_status'] ?? 'null'
            ));
            $this->line('');
        }

        if (! empty($result['variant_rows'])) {
            $this->info($mode === 'apply' ? 'Created / Updated exact rows' : 'Would create exact rows');
            $rows = collect($result['variant_rows'])->map(fn (array $row) => [
                $row['variant_id'],
                $row['variant_code'] ?? '-',
                $row['variant_name'] ?? '-',
                number_format((float) ($row['quantity'] ?? 0), 4, '.', ''),
                $row['existing_row_id'] ?? '-',
            ])->all();
            $this->table(['Variant ID', 'SKU', 'Ad', 'Qty', 'Existing Row'], $rows);
        }

        if (! empty($result['totals'])) {
            $totals = $result['totals'];
            $this->line('Totals:');
            $this->line('before operational = ' . number_format((float) ($totals['before_operational'] ?? 0), 4, '.', ''));
            $this->line('after operational exact = ' . number_format((float) ($totals['after_operational_exact'] ?? 0), 4, '.', ''));
            $this->line('double count = ' . number_format((float) ($totals['double_count'] ?? 0), 4, '.', ''));
            $this->line('');
        }

        if (! empty($result['guards'])) {
            $this->info('Guards');
            $guardRows = collect($result['guards'])->map(fn (array $guard) => [
                $guard['key'],
                $guard['passed'] ? 'PASS' : 'FAIL',
                $guard['message'],
            ])->all();
            $this->table(['Key', 'Durum', 'Mesaj'], $guardRows);
        }

        $before = $result['side_effects_before'] ?? [];
        $after = $result['side_effects_after'] ?? [];
        $this->info('Side effects');
        $this->table(
            ['Area', 'Before', 'After', 'Delta'],
            collect(array_keys($before + $after))->map(fn (string $key) => [
                $key,
                (string) ($before[$key] ?? 0),
                (string) ($after[$key] ?? 0),
                (string) (($after[$key] ?? 0) - ($before[$key] ?? 0)),
            ])->all()
        );

        if (! empty($result['remaining_legacy'])) {
            $remaining = $result['remaining_legacy'];
            $this->line('Remaining legacy warning count = ' . (int) ($remaining['count'] ?? 0));
            $this->line('Remaining legacy warning quantity = ' . number_format((float) ($remaining['quantity_on_hand'] ?? 0), 4, '.', ''));
        }

        if (($result['status'] ?? null) === 'blocked') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
