Hallo {{ $details['recipientName'] }},

@if ($kind === 'approval')
Dein Weltantrag „{{ $details['requestName'] }}“ für {{ $details['organizationName'] }} wurde genehmigt.
Der genehmigte Zeitraum ist vom {{ $details['startDate'] }} bis einschließlich {{ $details['endDate'] }}.

{{ $details['startsImmediately'] ? 'Die Bereitstellung deines Spielservers startet jetzt.' : 'Die Bereitstellung deines Spielservers beginnt am '.$details['provisioningAt'].' (Europe/Berlin).' }}
Die Einrichtung dauert einige Minuten. Sobald die Welt betretbar ist, erhältst du eine weitere E-Mail.
@else
Deine Welt für „{{ $details['requestName'] }}“ ist eingerichtet und betretbar. Dein Fachkraft-Spielaccount wurde erfolgreich freigeschaltet.
Die Welt steht dir im genehmigten Zeitraum vom {{ $details['startDate'] }} bis einschließlich {{ $details['endDate'] }} zur Verfügung.

Serveradresse, Spielname und Startpasswort findest du unter „Spielaccounts“ im Portal. Das Passwort wird dir dort nach Anmeldung angezeigt.
@endif

{{ $details['portalUrl'] }}

Du kennst diesen Antrag nicht? Wende dich bitte an die Leitung deiner Einrichtung.
