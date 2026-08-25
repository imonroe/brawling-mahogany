This is a test of "{{ $templateName }}" from {{ $teamName }}. No client received it.
@if (count($malformed) > 0)

A merge field is missing a brace, so it went out as written — look for "{{ implode('" and "', $malformed) }}".
@endif
@if (count($unknown) > 0)

No merge field is called {{ implode(', ', $unknown) }}.
@endif
@if (count($unresolved) > 0)

These had nothing behind them on the deal you chose: {{ implode(', ', $unresolved) }}.
@endif

----------------------------------------------------------------

{{ $bodyText }}
