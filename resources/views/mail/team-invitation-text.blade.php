You’ve been invited to {{ $teamName }}

@if ($inviterName){{ $inviterName }} has invited you to join {{ $teamName }} on {{ config('app.name') }}.@else You’ve been invited to join {{ $teamName }} on {{ config('app.name') }}.@endif

Accept the invitation:
{{ $acceptUrl }}

This link works once and expires on {{ $expiresAt->toFormattedDayDateString() }}.
