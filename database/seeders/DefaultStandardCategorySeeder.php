<?php

namespace Database\Seeders;

use App\Models\StandardCategory;
use Illuminate\Database\Seeder;

class DefaultStandardCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'PROMO', 'name' => 'Promosyon Ürünleri', 'parent_code' => null, 'product_family' => 'promotion', 'sort_order' => 1],
            ['code' => 'PROMO-KALEM', 'name' => 'Kalemler', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 10],
            ['code' => 'PROMO-KALEM-PLASTIK', 'name' => 'Plastik Kalemler', 'parent_code' => 'PROMO-KALEM', 'product_family' => 'promotion', 'sort_order' => 11],
            ['code' => 'PROMO-KALEM-METAL', 'name' => 'Metal Kalemler', 'parent_code' => 'PROMO-KALEM', 'product_family' => 'promotion', 'sort_order' => 12],
            ['code' => 'PROMO-DEFTER', 'name' => 'Defter & Ajandalar', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 20],
            ['code' => 'PROMO-DEFTER-AJANDA', 'name' => 'Ajandalar', 'parent_code' => 'PROMO-DEFTER', 'product_family' => 'promotion', 'sort_order' => 21],
            ['code' => 'PROMO-DEFTER-NOT', 'name' => 'Not Defterleri', 'parent_code' => 'PROMO-DEFTER', 'product_family' => 'promotion', 'sort_order' => 22],
            ['code' => 'PROMO-TEKNOLOJI', 'name' => 'Teknolojik Ürünler', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 30],
            ['code' => 'PROMO-TEKNOLOJI-USB', 'name' => 'USB Bellekler', 'parent_code' => 'PROMO-TEKNOLOJI', 'product_family' => 'promotion', 'sort_order' => 31],
            ['code' => 'PROMO-TEKNOLOJI-POWERBANK', 'name' => 'Powerbankler', 'parent_code' => 'PROMO-TEKNOLOJI', 'product_family' => 'promotion', 'sort_order' => 32],
            ['code' => 'PROMO-TEKNOLOJI-MOUSEPAD', 'name' => 'Wireless Mousepad', 'parent_code' => 'PROMO-TEKNOLOJI', 'product_family' => 'promotion', 'sort_order' => 33],
            ['code' => 'PROMO-TERMOS', 'name' => 'Termos, Matara & Kupa', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 40],
            ['code' => 'PROMO-TERMOS-MATARA', 'name' => 'Termos & Matara', 'parent_code' => 'PROMO-TERMOS', 'product_family' => 'promotion', 'sort_order' => 41],
            ['code' => 'PROMO-TERMOS-KUPA', 'name' => 'Kupalar', 'parent_code' => 'PROMO-TERMOS', 'product_family' => 'promotion', 'sort_order' => 42],
            ['code' => 'PROMO-CANTA', 'name' => 'Çanta & Taşıma Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 50],
            ['code' => 'PROMO-CANTA-BEZ', 'name' => 'Bez Çantalar', 'parent_code' => 'PROMO-CANTA', 'product_family' => 'promotion', 'sort_order' => 21],
            ['code' => 'PROMO-CANTA-SIRT', 'name' => 'Sırt Çantaları', 'parent_code' => 'PROMO-CANTA', 'product_family' => 'promotion', 'sort_order' => 22],
            ['code' => 'PROMO-OFIS', 'name' => 'Ofis & Masaüstü Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 60],
            ['code' => 'PROMO-OFIS-MOUSEPAD', 'name' => 'Mousepad-Bardakaltlığı', 'parent_code' => 'PROMO-OFIS', 'product_family' => 'promotion', 'sort_order' => 61],
            ['code' => 'PROMO-OFIS-MASAUSTU', 'name' => 'Masaüstü Setleri', 'parent_code' => 'PROMO-OFIS', 'product_family' => 'promotion', 'sort_order' => 62],
            ['code' => 'PROMO-AKSESUAR', 'name' => 'Anahtarlık, Rozet & Küçük Aksesuarlar', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 70],
            ['code' => 'PROMO-AKSESUAR-ANAHTARLIK', 'name' => 'Anahtarlıklar', 'parent_code' => 'PROMO-AKSESUAR', 'product_family' => 'promotion', 'sort_order' => 71],
            ['code' => 'PROMO-AKSESUAR-ROZET', 'name' => 'Rozetler', 'parent_code' => 'PROMO-AKSESUAR', 'product_family' => 'promotion', 'sort_order' => 72],
            ['code' => 'PROMO-SET', 'name' => 'Hediyelik Setler', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 80],
            ['code' => 'PROMO-SET-KUTU', 'name' => 'Set Kutuları', 'parent_code' => 'PROMO-SET', 'product_family' => 'promotion', 'sort_order' => 81],
            ['code' => 'PROMO-SET-KURUMSAL', 'name' => 'Kurumsal Setler', 'parent_code' => 'PROMO-SET', 'product_family' => 'promotion', 'sort_order' => 82],
            ['code' => 'PROMO-ODUL', 'name' => 'Plaket, Madalya & Ödül Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 90],
            ['code' => 'PROMO-ODUL-PLAKET', 'name' => 'Plaketler', 'parent_code' => 'PROMO-ODUL', 'product_family' => 'promotion', 'sort_order' => 91],
            ['code' => 'PROMO-ODUL-MADALYA', 'name' => 'Madalyalar', 'parent_code' => 'PROMO-ODUL', 'product_family' => 'promotion', 'sort_order' => 92],
            ['code' => 'PROMO-TEKSTIL', 'name' => 'Tekstil Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 100],
            ['code' => 'PROMO-TEKSTIL-TSHIRT', 'name' => 'Tişörtler', 'parent_code' => 'PROMO-TEKSTIL', 'product_family' => 'promotion', 'sort_order' => 101],
            ['code' => 'PROMO-TEKSTIL-SAPKA', 'name' => 'Şapkalar', 'parent_code' => 'PROMO-TEKSTIL', 'product_family' => 'promotion', 'sort_order' => 102],
            ['code' => 'PROMO-OUTDOOR', 'name' => 'Outdoor & Araç Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 110],
            ['code' => 'PROMO-OUTDOOR-SEMSIYE', 'name' => 'Şemsiyeler', 'parent_code' => 'PROMO-OUTDOOR', 'product_family' => 'promotion', 'sort_order' => 111],
            ['code' => 'PROMO-OUTDOOR-ARAC', 'name' => 'Araç İçi Ürünler', 'parent_code' => 'PROMO-OUTDOOR', 'product_family' => 'promotion', 'sort_order' => 112],
            ['code' => 'PROMO-MATBAA', 'name' => 'Matbaa & Kağıt Promosyon Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 120],
            ['code' => 'PROMO-MATBAA-TAKVIM', 'name' => 'Takvimler', 'parent_code' => 'PROMO-MATBAA', 'product_family' => 'promotion', 'sort_order' => 121],
            ['code' => 'PROMO-MATBAA-MOUSEPAD', 'name' => 'Baskılı Mousepad', 'parent_code' => 'PROMO-MATBAA', 'product_family' => 'promotion', 'sort_order' => 122],
            ['code' => 'PROMO-ETIKET', 'name' => 'Etiket / Sticker / Baskı Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 130],
            ['code' => 'PROMO-ETIKET-STICKER', 'name' => 'Sticker Ürünleri', 'parent_code' => 'PROMO-ETIKET', 'product_family' => 'promotion', 'sort_order' => 131],
            ['code' => 'PROMO-AMBALAJ', 'name' => 'Ambalaj / Kutu / Set Kutuları', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 140],
            ['code' => 'PROMO-AMBALAJ-KUTU', 'name' => 'Promosyon Kutuları', 'parent_code' => 'PROMO-AMBALAJ', 'product_family' => 'promotion', 'sort_order' => 141],
            ['code' => 'PROMO-TAKVIM', 'name' => 'Takvimler', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 150],
            ['code' => 'PROMO-SAAT', 'name' => 'Saatler', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 160],
            ['code' => 'PROMO-SAAT-DUVAR', 'name' => 'Duvar Saatleri', 'parent_code' => 'PROMO-SAAT', 'product_family' => 'promotion', 'sort_order' => 161],
            ['code' => 'PROMO-DIGER', 'name' => 'Diğer Promosyon Ürünleri', 'parent_code' => 'PROMO', 'product_family' => 'promotion', 'sort_order' => 170],
            ['code' => 'PRINT', 'name' => 'Matbaa Ürünleri', 'parent_code' => null, 'product_family' => 'print', 'sort_order' => 1],
            ['code' => 'PRINT-KARTVIZIT', 'name' => 'Kartvizit', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 10],
            ['code' => 'PRINT-BROSUR', 'name' => 'Broşür', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 20],
            ['code' => 'PRINT-ETIKET', 'name' => 'Etiket', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 30],
            ['code' => 'PRINT-KUTU', 'name' => 'Kutu', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 40],
            ['code' => 'PRINT-AFIS', 'name' => 'Afiş & Poster', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 50],
            ['code' => 'PRINT-TAKVIM', 'name' => 'Matbaa Takvimleri', 'parent_code' => 'PRINT', 'product_family' => 'print', 'sort_order' => 60],
            ['code' => 'CATALOG', 'name' => 'Sistem & Katalog Kökü', 'parent_code' => null, 'product_family' => 'promotion', 'sort_order' => 999],
        ];

        foreach ($rows as $row) {
            $parent = $row['parent_code']
                ? StandardCategory::query()->where('code', $row['parent_code'])->first()
                : null;

            $category = StandardCategory::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'parent_id' => $parent?->id,
                    'name' => $row['name'],
                    'slug' => StandardCategory::generateSlug($row['name']),
                    'product_family' => $row['product_family'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                    'visible_in_catalog' => true,
                ]
            );

            $category->updatePath();
        }
    }
}
