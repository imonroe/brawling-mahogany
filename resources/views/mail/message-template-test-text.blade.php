This is a test of "{{ $templateName }}" from {{ $teamName }}. No client received it.
@if (count($problems) > 0)

These merge fields had nothing behind them on the deal you chose: {{ implode(', ', $problems) }}.
@endif

----------------------------------------------------------------

{{ $bodyText }}
