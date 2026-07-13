# Prodelya UI Spacing And Bordered Block Standard

## Amaç
- Borderlı kartlar, başlık blokları, sekmeler, sticky paneller ve üst seviye template bölümleri dip dibe görünmemelidir.
- Çözüm wrapper seviyesinde `gap` kullanan ortak `pd-*` primitive’lerle kurulmalıdır.
- Global `.card`, `.panel`, `.box`, `.section` margin hack’i yasaktır.

## Canonical spacing
- Top-level page/block gap: `var(--pd-space-page)` => `14px`
- Section/sticky card gap: `var(--pd-space-section)` => `12px`
- Nested card/content gap: `var(--pd-space-card)` => `10px`
- Inline action/list gap: `var(--pd-space-inline)` => `8px`
- Tight helper gap: `var(--pd-space-tight)` => `6px`

## Shared primitives
- `.pd-page-stack`: top-level vertical stack
- `.pd-section-stack`: section or sticky sidebar card stack
- `.pd-card-stack`: nested card content stack
- `.pd-two-column-layout`: multi-column wrapper with canonical column gap
- `.pd-inline-stack`: inline / wrapping action cluster
- `.pd-tight-stack`: compact nested stack

## Kullanım kuralları
- Kartlara margin verme; wrapper’a `display:grid` veya `display:flex` ile `gap` ver.
- Top-level ve nested spacing aynı utility ile çözülmemelidir.
- Aynı sayfada iki kolon arası boşluk için `pd-two-column-layout`, dikey akış için `pd-page-stack` veya `pd-section-stack` kullan.
- Responsive görünümde `pd-page-stack`, `pd-section-stack` ve `pd-two-column-layout` 760px altında `10px` gap’e iner.

## Yasak yaklaşım
```css
.card {
  margin-bottom: 12px;
}
```

## Örnek
```html
<div class="pd-page-stack">
  <section class="pd-card">...</section>
  <section class="pd-two-column-layout">
    <div class="pd-page-stack">...</div>
    <aside class="pd-section-stack">...</aside>
  </section>
</div>
```

## Rollout audit listesi
- teklifler
- siparişler
- grafik
- tedarik
- üretim
- teslimat
- finans
- cari
- ayarlar
- Super Admin
- Product Data Hub