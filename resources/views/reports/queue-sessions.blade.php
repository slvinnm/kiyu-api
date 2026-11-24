<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Queue Session Report</title>
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
    <h1>Queue Session Report</h1>
    <div class="meta">Period: {{ $from }} to {{ $to }}</div>

    <table class="summary" style="margin-bottom: 16px;">
        <tr>
            <td><strong>Total sessions</strong></td><td>{{ $summary['total_sessions'] }}</td>
            <td><strong>Completed</strong></td><td>{{ $summary['completed_sessions'] }}</td>
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
                <th>Session</th>
                <th>Status</th>
                <th>Channel</th>
                <th>Phone</th>
                <th>Customer</th>
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
                <tr><td colspan="7">No queue sessions in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
