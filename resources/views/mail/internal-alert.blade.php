{{--
    S91 — internal alert. Team-facing, so the words are the internal ones.

    A headline, a sentence, and one link. There is deliberately no banner
    above the headline: the first version put the same string in both, which
    on the shortest email in the product reads as a rendering bug rather than
    as emphasis.
--}}
@extends('mail.layout')

@section('preheader'){{ $detail }}@endsection

@section('headline'){{ $headline }}@endsection

@section('content')
    <p style="margin:0 0 20px;word-break:break-word;">{{ $detail }}</p>
@endsection

@section('cta')
    @include('mail.partials.button', [
        'url' => $actionUrl,
        'label' => $actionLabel,
        'brand' => $brand,
    ])
@endsection
