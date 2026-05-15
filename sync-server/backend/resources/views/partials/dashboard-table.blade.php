<div class="card">
    <div class="table-meta">
        <h3 style="margin:0;">{{ $title }}</h3>
        <span class="muted">Top {{ $rows->count() }}</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Events</th>
                    <th>Tokens</th>
                    <th>Avg Cache</th>
                    <th>Avg Input</th>
                    <th>Avg Output</th>
                    <th>Cost</th>
                    <th>Avg Cost/Event</th>
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
