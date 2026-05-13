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
                    <th>Cost</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>
                            {{ $row->label ?? 'Unknown' }}
                            @if(! empty($row->meta))
                                <div class="muted">{{ $row->meta }}</div>
                            @endif
                        </td>
                        <td>{{ number_format((int) $row->event_count) }}</td>
                        <td>{{ number_format((int) $row->total_tokens) }}</td>
                        <td>{{ $row->total_cost !== null ? '$'.number_format((float) $row->total_cost, 2) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="muted">No synced usage matches these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
