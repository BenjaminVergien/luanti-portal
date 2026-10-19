Einladung zum Luanti-Portal

{{-- This is a plain-text MIME body: preserve names literally without HTML or Markdown processing. --}}
@if($invitation->organization)
Du wurdest zur Einrichtung {!! $invitation->organization->name !!} als {!! $invitation->membership_role->label() !!} eingeladen.
@else
Du wurdest als {!! $invitation->internal_role->label() !!} eingeladen.
@endif

Diese Einladung ist für {!! $invitation->email !!} bestimmt.

Wenn du noch kein Konto im Luanti-Portal hast, klicke auf „Einladung öffnen“ und registriere dich zunächst mit der E-Mail-Adresse, an die diese Einladung gesendet wurde. Bestätige anschließend deine E-Mail-Adresse über die Bestätigungs-E-Mail. Danach kannst du die Einladung im Portal annehmen.

Wenn du bereits ein Konto im Luanti-Portal besitzt, klicke auf „Einladung öffnen“ und melde dich mit der eingeladenen E-Mail-Adresse an, um die Einladung anzunehmen. Falls deine E-Mail-Adresse noch nicht bestätigt ist, bestätige sie bitte zuerst.

Einladung öffnen: {!! $acceptUrl !!}

Die Einladung gilt bis {!! $invitation->expires_at->timezone('Europe/Berlin')->format('d.m.Y H:i') !!} Uhr (Europe/Berlin).

Du erwartest keine Einladung? Dann kannst du diese E-Mail ignorieren. Ohne deine Annahme wird keine Mitgliedschaft oder interne Rolle vergeben.

Bitte teile diesen persönlichen Link nicht mit anderen.
