<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Visit Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f2f2f2; }
        .summary td { border: none; padding: 2px 6px; }
    </style>
</head>
<body>
    <h1>Visit Report</h1>
    <div class="meta">Period: {{ $from }} to {{ $to }}</div>

    <table class="summary" style="margin-bottom: 16px;">
        <tr>
            <td><strong>Total visits</strong></td><td>{{ $summary['total_visits'] }}</td>
            <td><strong>Completed</strong></td><td>{{ $summary['completed_visits'] }}</td>
            <td><strong>Completion rate</strong></td><td>{{ $summary['completion_rate'] }}%</td>
        </tr>
        <tr>
            <td><strong>Avg wait</strong></td>
            <td>{{ $summary['average_wait_minutes'] !== null ? $summary['average_wait_minutes'].' min' : '-' }}</td>
            <td></td><td></td><td></td><td></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Visit</th>
                <th>Status</th>
                <th>Channel</th>
                <th>MRN</th>
                <th>Client</th>
                <th>Department</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="7">No visits in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
