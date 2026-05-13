@if ($paginator->hasPages())
    <div class="pagination">
        <div class="muted">
            Showing {{ number_format($paginator->firstItem()) }}-{{ number_format($paginator->lastItem()) }}
            of {{ number_format($paginator->total()) }}
        </div>
        <div class="links">
            @if ($paginator->onFirstPage())
                <span class="page disabled">Prev</span>
            @else
                <a class="page" href="{{ $paginator->previousPageUrl() }}" rel="prev">Prev</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page disabled">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page active">{{ $page }}</span>
                        @else
                            <a class="page" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="page" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="page disabled">Next</span>
            @endif
        </div>
    </div>
@endif
