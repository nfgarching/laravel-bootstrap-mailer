<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Dokument herunterladen</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 32rem">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h5 mb-3">Dokument herunterladen</h1>

      @if ($reason)
        <div class="alert alert-warning mb-0">{{ $reason }}</div>
      @else
        <div class="d-flex align-items-center gap-2 mb-3">
          <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" class="text-secondary flex-shrink-0">
            <path d="M14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h5.5zm-3 0A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V4.5z"/>
          </svg>
          <span class="fw-semibold text-break">{{ $link->name }}</span>
        </div>

        <form method="POST" action="{{ route('nfmailer.download.file', $token) }}">
          @csrf

          @if ($link->hasPin())
            <label for="pin" class="form-label">PIN</label>
            <input type="text" id="pin" name="pin" inputmode="numeric" autocomplete="one-time-code"
                   class="form-control form-control-lg mb-1 @error('pin') is-invalid @enderror"
                   maxlength="12" required autofocus>
            @if ($errors->has('pin'))
              <div class="invalid-feedback d-block">{{ $errors->first('pin') }}</div>
            @else
              <div class="form-text">Die PIN haben Sie getrennt von dieser E-Mail erhalten.</div>
            @endif
          @endif

          <button type="submit" class="btn btn-primary w-100 mt-3">Herunterladen</button>
        </form>

        <p class="small text-body-secondary mt-3 mb-0">
          @if ($link->expires_at)
            Abrufbar bis {{ $link->expires_at->format('d.m.Y') }}.
          @endif
          @if ($link->max_downloads)
            @php $left = max(0, $link->max_downloads - $link->download_count); @endphp
            Noch {{ $left }} {{ $left === 1 ? 'Abruf' : 'Abrufe' }} möglich.
          @endif
        </p>
      @endif
    </div>
  </div>
</main>
</body>
</html>
