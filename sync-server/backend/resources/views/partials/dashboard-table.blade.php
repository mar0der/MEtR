@php
    $tableKey = $tableKey ?? 'table';
    $sortParam = $tableKey.'_sort';
    $dirParam = $tableKey.'_dir';
    $activeSort = request($sortParam, 'events');
    $activeDir = request($dirParam, 'desc') === 'asc' ? 'asc' : 'desc';
    $headers = [
        'name' => 'Name',
        'events' => 'Events',
        'tokens' => 'Tokens',
        'avg_cache' => 'Avg Cache',
        'avg_input' => 'Avg Input',
        'avg_output' => 'Avg Output',
        'cost' => 'Cost',
        'avg_cost' => 'Avg Cost/Event',
    ];
    $sortUrl = function (string $column) use ($sortParam, $dirParam, $activeSort, $activeDir) {
        $defaultDir = $column === 'name' ? 'asc' : 'desc';
        $nextDir = $activeSort === $column ? ($activeDir === 'asc' ? 'desc' : 'asc') : $defaultDir;

        return request()->fullUrlWithQuery([
            $sortParam => $column,
            $dirParam => $nextDir,
        ]);
    };
@endphp

<div class="card">
    <div class="table-meta">
        <h3 style="margin:0;">{{ $title }}</h3>
        <span class="muted">Top {{ $rows->count() }}</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    @foreach($headers as $column => $label)
                        @php
                            $isActive = $activeSort === $column;
                            $indicator = $isActive ? ($activeDir === 'asc' ? '^' : 'v') : '';
                        @endphp
                        <th>
                            <a class="sortable-header {{ $isActive ? 'active' : '' }}" href="{{ $sortUrl($column) }}">
                                <span>{{ $label }}</span>
                                <span class="sort-indicator">{{ $indicator }}</span>
                            </a>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php
                        $eventCount = max(1, (int) $row->event_count);
                        $avgCached = (int) round(((int) ($row->cached_tokens ?? 0)) / $eventCount);
                        $avgInput = (int) round(((int) ($row->effective_input_tokens ?? 0)) / $eventCount);
                        $avgOutput = (int) round(((int) ($row->output_tokens ?? 0)) / $eventCount);
                        $avgCost = $row->total_cost !== null ? ((float) $row->total_cost) / $eventCount : null;
                    @endphp
                    <tr>
                        <td>
                            {{ $row->label ?? 'Unknown' }}
                            @if(! empty($row->meta))
                                <div class="muted">{{ $row->meta }}</div>
                            @endif
                        </td>
                        <td>{{ number_format((int) $row->event_count) }}</td>
                        <td>{{ number_format((int) $row->total_tokens) }}</td>
                        <td>{{ number_format($avgCached) }}</td>
                        <td>{{ number_format($avgInput) }}</td>
                        <td>{{ number_format($avgOutput) }}</td>
                        <td>{{ $row->total_cost !== null ? '$'.number_format((float) $row->total_cost, 2) : '—' }}</td>
                        <td>{{ $avgCost !== null ? '$'.number_format($avgCost, 3) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="muted">No synced usage matches these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
