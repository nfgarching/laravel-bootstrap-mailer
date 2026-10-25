{!! $text !!}
@if (count($links))

Dokumente zum Download:
@foreach ($links as $link)
- {!! $link['name'] !!}: {!! $link['url'] !!}
@endforeach
@if ($links[0]['expires'] ?? null)
(abrufbar bis {{ $links[0]['expires'] }})
@endif
@endif
@if ($signature)

--
{!! $signature !!}
@endif
