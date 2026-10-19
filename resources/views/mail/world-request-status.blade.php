<x-mail-layout :heading="$kind === 'approval' ? 'Dein Weltantrag wurde genehmigt' : 'Deine Welt ist bereit'"
    :action-label="$kind === 'approval' ? 'Weltantrag im Portal ansehen' : 'Spielzugang im Portal öffnen'" :action-url="$details['portalUrl']">
    <p style="margin: 0 0 20px;">Hallo {{ $details['recipientName'] }},</p>
    @if ($kind === 'approval')
        <p style="margin: 0 0 20px;">Dein Weltantrag „{{ $details['requestName'] }}“ für {{ $details['organizationName'] }} wurde genehmigt.</p>
        <p style="margin: 0 0 20px;">Der genehmigte Zeitraum ist vom {{ $details['startDate'] }} bis einschließlich {{ $details['endDate'] }}.</p>
        <p style="margin: 0;">{{ $details['startsImmediately'] ? 'Die Bereitstellung deines Spielservers startet jetzt.' : 'Die Bereitstellung deines Spielservers beginnt am '.$details['provisioningAt'].' (Europe/Berlin).' }}
            Die Einrichtung dauert einige Minuten. Sobald die Welt betretbar ist, erhältst du eine weitere E-Mail.</p>
    @else
        <p style="margin: 0 0 20px;">Deine Welt für „{{ $details['requestName'] }}“ ist eingerichtet und betretbar. Dein Fachkraft-Spielaccount wurde erfolgreich freigeschaltet.</p>
        <p style="margin: 0 0 20px;">Die Welt steht dir im genehmigten Zeitraum vom {{ $details['startDate'] }} bis einschließlich {{ $details['endDate'] }} zur Verfügung.</p>
        <p style="margin: 0;">Serveradresse, Spielname und Startpasswort findest du unter „Spielaccounts“ im Portal. Das Passwort wird dir dort nach Anmeldung angezeigt.</p>
    @endif
    <x-slot name="footer">
        <p style="margin: 0; color: #6b7280; font-size: 14px;">Du kennst diesen Antrag nicht? Wende dich bitte an die Leitung deiner Einrichtung.</p>
    </x-slot>
</x-mail-layout>
