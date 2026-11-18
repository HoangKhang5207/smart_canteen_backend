@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
    $itemLabel = $label ?? 'bản ghi';
    $currentPage = $paginator->currentPage();
    $lastPage = max(1, $paginator->lastPage());

    $pages = [];
    for ($p = 1; $p <= $lastPage; $p++) {
        if ($p === 1 || $p === $lastPage || abs($p - $currentPage) <= 1) {
            $pages[] = $p;
        } elseif (end($pages) !== '...') {
            $pages[] = '...';
        }
    }
@endphp

@if ($paginator->total() > 0)
<div class="pagination-bar" role="region" aria-label="Thông tin phân trang {{ $itemLabel }}">
    <div class="pagination-summary">
        Hiển thị <strong>{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</strong>
        trong tổng số <strong>{{ $paginator->total() }}</strong> {{ $itemLabel }}
        <span style="color: var(--text-muted);">· Trang {{ $currentPage }}/{{ $lastPage }}</span>
    </div>

    @if ($paginator->hasPages())
    <nav class="pagination-nav" aria-label="Điều hướng phân trang {{ $itemLabel }}">
        @if ($paginator->onFirstPage())
            <span class="page-btn disabled" aria-disabled="true">← Trước</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="page-btn" rel="prev">← Trước</a>
        @endif

        @foreach ($pages as $pageItem)
            @if ($pageItem === '...')
                <span class="page-ellipsis" aria-hidden="true">…</span>
            @elseif ($pageItem === $currentPage)
                <span class="page-btn active" aria-current="page">{{ $pageItem }}</span>
            @else
                <a href="{{ $paginator->url($pageItem) }}" class="page-btn" aria-label="Trang {{ $pageItem }}">{{ $pageItem }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="page-btn" rel="next">Sau →</a>
        @else
            <span class="page-btn disabled" aria-disabled="true">Sau →</span>
        @endif
    </nav>
    @endif
</div>
@endif
