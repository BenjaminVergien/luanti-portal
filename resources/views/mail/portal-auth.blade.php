<x-mail-layout :heading="$heading" :action-label="$actionLabel" :action-url="$actionUrl">
    <p style="margin: 0 0 20px;">{{ $introduction }}</p>
    <p style="margin: 0;">{{ $nextStep }}</p>
    <x-slot name="footer">
        <p style="margin: 0 0 16px;">Dieser Link ist ab Anforderung {{ $expiryMinutes }} Minuten gültig. Ist er abgelaufen, fordere im Portal eine neue E-Mail an.</p>
        <p style="margin: 0; color: #6b7280; font-size: 14px;">{{ $ignoreMessage }}</p>
    </x-slot>
</x-mail-layout>
