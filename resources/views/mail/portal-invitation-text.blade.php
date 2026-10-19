Einladung zum Luanti-Portal

{{-- This is a plain-text MIME body: preserve names literally without HTML or Markdown processing. --}}
@if($invitation->organization)
Du wurdest zur Einrichtung {!! $invitation->organization->name !!} als {!! $invitation->membership_role->label() !!} eingeladen.
@else
Du wurdest als {!! $invitation->internal_role->label() !!} eingeladen.
@endif

Diese Einladung ist für {!! $invitation->email !!} bestimmt.

Melde dich mit der eingeladenen E-Mail-Adresse an oder registriere dich zuerst. Bestätige anschließend deine E-Mail-Adresse und nimm die Einladung an.

Einladung öffnen: {!! $acceptUrl !!}

Die Einladung gilt bis {!! $invitation->expires_at->timezone('Europe/Berlin')->format('d.m.Y H:i') !!} Uhr (Europe/Berlin).

Du erwartest keine Einladung? Dann kannst du diese E-Mail ignorieren. Ohne deine Annahme wird keine Mitgliedschaft oder interne Rolle vergeben.

Bitte teile diesen persönlichen Link nicht mit anderen.
