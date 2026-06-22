@php
    $limitValue = request('limit', $filters['limit'] ?? 50);
    $firstItem = method_exists($paginator, 'firstItem') ? $paginator->firstItem() : null;
    $lastItem = method_exists($paginator, 'lastItem') ? $paginator->lastItem() : null;
@endphp

<div class="pd-catalog-pagination">
    <div class="pd-muted">
        Toplam {{ number_format($paginator->total(), 0, ',', '.') }} kayıt
        @if($firstItem)
            · {{ number_format($firstItem, 0, ',', '.') }} - {{ number_format($lastItem, 0, ',', '.') }} arası gösteriliyor
            · Sayfa {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
        @endif
    </div>
    <form method="GET" action="{{ url()->current() }}" class="pd-catalog-limit-form">
        @foreach(request()->except(['limit', 'page', 'warningRows_page']) as $key => $value)
            @if(is_array($value))
                @foreach($value as $item)
                    <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                @endforeach
            @else
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label class="pd-label" style="margin:0;">Kayıt limiti</label>
        <select name="limit" class="pd-select" onchange="this.form.submit()">
            @foreach([50, 100, 250, 500] as $limit)
                <option value="{{ $limit }}" @selected((string) $limitValue === (string) $limit)>{{ $limit }}</option>
            @endforeach
            <option value="all" @selected($limitValue === 'all')>Tümü</option>
        </select>
        @if($limitValue === 'all')
            <span class="pd-badge pd-badge-amber">Tüm ürünleri göstermek ekranı yavaşlatabilir.</span>
        @endif
    </form>
    <div class="pd-catalog-page-links">
        {{ $paginator->onEachSide(1)->links() }}
    </div>
</div>
