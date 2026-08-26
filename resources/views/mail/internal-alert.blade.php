{{--
    S91 — internal alert. Team-facing, so the words are the internal ones.

    The banner tone is `danger` and it is the only thing on this message that
    is loud. Everything below it is a sentence, a reason, and one link.
--}}
@extends('mail.layout')

@section('preheader'){{ $detail }}@endsection

@section('banner')
    @include('mail.partials.notice', ['tone' => 'danger', 'body' => $headline])
@endsection

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

@if ($footnote)
    @section('footnote'){{ $footnote }}@endsection
@endif
