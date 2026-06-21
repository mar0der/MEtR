@php
    $rows = $rows ?? [];
    $title = $title ?? 'Grouping';
    $formatCostPerMillion = fn (?float $value) => $value === null ? '-' : '$'.number_format($value, 2);
@endphp
<div class="card">
    <div class="table-meta">
        <h3 style="margin:0;">{{ $title }}</h3>
        <span class="muted">{{ number_format(count($rows)) }} row(s)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="text-align:right;">Events</th>
                    <th style="text-align:right;">Cost</th>
                    <th style="text-align:right;">Paid</th>
                    <th style="text-align:right;">Cached</th>
                    <th style="text-align:right;">Input</th>
                    <th style="text-align:right;">Output</th>
                    <th style="text-align:right;">Total Tokens</th>
                    <th style="text-align:right;" title="API-equivalent cost per 1M tokens for this mix">Cost / 1M</th>
                    <th style="text-align:right;" title="Real cost per 1M tokens after subscription/quota discount">Eff. Cost / 1M</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td style="text-align:right;">{{ number_format($row['events']) }}</td>
                        <td style="text-align:right;">${{ number_format((float) $row['cost'], 2) }}</td>
                        <td style="text-align:right;">${{ number_format((float) ($row['subscription_cost'] ?? 0), 2) }}</td>
                        <td style="text-align:right;">{{ number_format($row['cached']) }}</td>
                        <td style="text-align:right;">{{ number_format($row['input']) }}</td>
                        <td style="text-align:right;">{{ number_format($row['output']) }}</td>
                        <td style="text-align:right;">{{ number_format($row['total_tokens']) }}</td>
                        <td style="text-align:right;">{{ $formatCostPerMillion($row['cost_per_million'] ?? null) }}</td>
                        <td style="text-align:right;">{{ $formatCostPerMillion($row['effective_cost_per_million'] ?? null) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="muted">No data for this grouping.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
