<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_service_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('service_code')->unique();
            $table->string('service_name');
            $table->string('category')->nullable();
            $table->enum('default_direction', ['debit', 'credit'])->default('debit');
            $table->decimal('default_amount', 12, 2)->nullable();
            $table->string('currency', 10)->default('TRY');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('tenant_service_definitions')->insert([
            [
                'service_code' => 'ONBOARDING',
                'service_name' => 'Kurulum ve Onboarding',
                'category' => 'Kurulum',
                'default_direction' => 'debit',
                'default_amount' => 5000,
                'currency' => 'TRY',
                'description' => 'İlk kurulum, temel ayar ve kullanıcı başlangıç hizmeti.',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_code' => 'DOMAIN',
                'service_name' => 'Domain ve Panel Hizmeti',
                'category' => 'Domain / SSL',
                'default_direction' => 'debit',
                'default_amount' => 0,
                'currency' => 'TRY',
                'description' => 'Panel alan adı, yönlendirme ve özel domain hazırlık hizmeti.',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_code' => 'IMPORT',
                'service_name' => 'XML / CSV Veri İşleme Hizmeti',
                'category' => 'Entegrasyon',
                'default_direction' => 'debit',
                'default_amount' => 0,
                'currency' => 'TRY',
                'description' => 'Ürün içe aktarma, eşleme ve katalog hazırlık hizmeti.',
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_code' => 'SUPPORT',
                'service_name' => 'Ek Destek Hizmeti',
                'category' => 'Destek',
                'default_direction' => 'debit',
                'default_amount' => 0,
                'currency' => 'TRY',
                'description' => 'Paket dışında verilen ilave destek hizmetleri.',
                'is_active' => true,
                'sort_order' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_code' => 'DEVELOPMENT',
                'service_name' => 'Özel Geliştirme',
                'category' => 'Geliştirme',
                'default_direction' => 'debit',
                'default_amount' => 0,
                'currency' => 'TRY',
                'description' => 'Tenant özel geliştirme ve revizyon hizmeti.',
                'is_active' => true,
                'sort_order' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_service_definitions');
    }
};
