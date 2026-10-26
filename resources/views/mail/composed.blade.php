<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $mailSubject }}</title>
</head>
<body style="margin:0;padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2328;background:#ffffff;">
  <div style="max-width:680px;">
    {!! nl2br(e($text)) !!}

    @if (count($links))
      <div style="margin:24px 0;padding:12px 16px;border:1px solid #d0d7de;border-radius:6px;background:#f6f8fa;">
        <div style="font-weight:bold;margin-bottom:6px;">Dokumente zum Download</div>
        @foreach ($links as $link)
          <div style="margin:4px 0;">
            <a href="{{ $link['url'] }}" style="color:#0a58ca;">{{ $link['name'] }}</a>
          </div>
        @endforeach
        @if ($links[0]['expires'] ?? null)
          <div style="margin-top:8px;font-size:12px;color:#57606a;">Die Links sind bis {{ $links[0]['expires'] }} abrufbar.</div>
        @endif
        @if ($pinNote)
          <div style="margin-top:4px;font-size:12px;color:#57606a;">{{ $pinNote }}</div>
        @endif
      </div>
    @endif

    @if ($signature)
      <div style="margin-top:24px;padding-top:12px;border-top:1px solid #d0d7de;font-size:12px;color:#57606a;">
        {!! nl2br(e($signature)) !!}
      </div>
    @endif
  </div>
</body>
</html>
