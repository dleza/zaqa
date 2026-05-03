<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Report' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0 0 8px; color: #0076BD; }
        .meta { font-size: 10px; color: #64748b; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        th { background: #f1f5f9; font-weight: 600; }
        .numeric { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ $title ?? 'Report summary' }}</h1>
    <div class="meta">
        Period: {{ $period_from ?? '' }} — {{ $period_to ?? '' }}
        @if(!empty($generated_at))
            · Generated {{ $generated_at }}
        @endif
    </div>
    @hasSection('body')
        @yield('body')
    @else
        {!! $body ?? '' !!}
    @endif
</body>
</html>
