<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\TenantNumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NumberGenerationService
{
    /**
     * Generate a document number for a tenant
     */
    public function generateNumber(int $tenantId, string $documentType, ?string $prefix = null): string
    {
        return DB::transaction(function () use ($tenantId, $documentType, $prefix) {
            // Get or create the number sequence for this tenant and document type
            $sequence = TenantNumberSequence::lockForUpdate()
                ->where('tenant_account_id', $tenantId)
                ->where('document_type', $documentType)
                ->first();

            if (!$sequence) {
                $sequence = $this->createDefaultSequence($tenantId, $documentType, $prefix);
            }

            // Generate the number
            $currentYear = date('Y');
            
            // Reset sequence if year changed
            if ($sequence->year != $currentYear) {
                $sequence->year = $currentYear;
                $sequence->current_number = 1;
            } else {
                $sequence->current_number++;
            }

            $sequence->save();

            // Format the number
            $number = $this->formatNumber($sequence, $sequence->current_number);

            // Log the generation for audit purposes
            Log::info('Document number generated', [
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'number' => $number,
                'sequence_id' => $sequence->id,
            ]);

            return $number;
        });
    }

    /**
     * Create a default number sequence
     */
    protected function createDefaultSequence(int $tenantId, string $documentType, ?string $prefix = null): TenantNumberSequence
    {
        $defaultFormats = [
            'quote' => ['prefix' => 'TK', 'format' => '{PREFIX}-{YYYY}-{SEQ4}'],
            'order' => ['prefix' => 'SP', 'format' => '{PREFIX}-{YYYY}-{SEQ4}'],
            'work_form' => ['prefix' => 'IF', 'format' => '{PREFIX}-{YYYY}-{SEQ4}'],
        ];

        $formatConfig = $defaultFormats[$documentType] ?? [
            'prefix' => 'DOC',
            'format' => '{PREFIX}-{YYYY}-{SEQ4}'
        ];

        if ($prefix) {
            $formatConfig['prefix'] = $prefix;
        }

        return TenantNumberSequence::create([
            'tenant_account_id' => $tenantId,
            'document_type' => $documentType,
            'prefix' => $formatConfig['prefix'],
            'format' => $formatConfig['format'],
            'year' => date('Y'),
            'current_number' => 1,
        ]);
    }

    /**
     * Format the number according to the sequence format
     */
    protected function formatNumber(TenantNumberSequence $sequence, int $number): string
    {
        $format = $sequence->format;
        
        // Replace placeholders
        $format = str_replace('{PREFIX}', $sequence->prefix, $format);
        $format = str_replace('{YYYY}', $sequence->year, $format);
        $format = str_replace('{YY}', substr($sequence->year, -2), $format);
        
        // Replace sequence placeholders
        $format = str_replace('{SEQ}', $number, $format);
        $format = str_replace('{SEQ4}', str_pad($number, 4, '0', STR_PAD_LEFT), $format);
        $format = str_replace('{SEQ6}', str_pad($number, 6, '0', STR_PAD_LEFT), $format);
        
        return $format;
    }

    /**
     * Get the next number without incrementing
     */
    public function getNextNumber(int $tenantId, string $documentType): ?string
    {
        $sequence = TenantNumberSequence::where('tenant_account_id', $tenantId)
            ->where('document_type', $documentType)
            ->first();

        if (!$sequence) {
            return null;
        }

        $nextNumber = $sequence->current_number + 1;
        
        return $this->formatNumber($sequence, $nextNumber);
    }

    /**
     * Reset sequence for a tenant and document type
     */
    public function resetSequence(int $tenantId, string $documentType, int $startNumber = 1): bool
    {
        try {
            DB::transaction(function () use ($tenantId, $documentType, $startNumber) {
                $sequence = TenantNumberSequence::lockForUpdate()
                    ->where('tenant_account_id', $tenantId)
                    ->where('document_type', $documentType)
                    ->first();

                if ($sequence) {
                    $sequence->current_number = $startNumber;
                    $sequence->year = date('Y');
                    $sequence->save();
                }
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to reset number sequence', [
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all sequences for a tenant
     */
    public function getTenantSequences(int $tenantId): array
    {
        return TenantNumberSequence::where('tenant_account_id', $tenantId)
            ->orderBy('document_type')
            ->get()
            ->map(function ($sequence) {
                return [
                    'document_type' => $sequence->document_type,
                    'prefix' => $sequence->prefix,
                    'format' => $sequence->format,
                    'current_number' => $sequence->current_number,
                    'year' => $sequence->year,
                    'next_number' => $this->formatNumber($sequence, $sequence->current_number + 1),
                ];
            })
            ->toArray();
    }
}
