@extends('mail.layout', ['preview' => $subjectLine])

@section('content')
    <h1 style="margin:0 0 14px;font-size:20px;font-weight:600;letter-spacing:-0.01em;">{{ $subjectLine }}</h1>

    {{--
        Already rendered by ContentRenderer: Markdown with raw HTML stripped
        and unsafe links refused, so an administrator cannot put a script into
        every player's inbox. See D20.
    --}}
    {!! $bodyHtml !!}
@endsection

@section('footer')
    <p style="margin:8px 0 0;">
        You are receiving this because you have an account
        @if ($gameName !== '') at {{ $gameName }} @endif
        with this address.
    </p>
@endsection
