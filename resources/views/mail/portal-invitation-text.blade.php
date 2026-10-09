Einladung zum Luanti-Portal

{{-- This is a plain-text MIME body: preserve names literally without HTML or Markdown processing. --}}
@if($invitation->organization)
Du wurdest zur Einrichtung {!! $invitation->organization->name !!} als {!! $invitation->membership_role->label() !!} eingeladen.
@else
Du wurdest als {!! $invitation->internal_role->label() !!} eingeladen.
@endif

Melde dich mit der eingeladenen E-Mail-Adresse an oder registriere dich zuerst. Bestätige anschließend deine E-Mail-Adresse und nimm die Einladung an.

Einladung öffnen: {!! $acceptUrl !!}

Die Einladung gilt bis {!! $invitation->expires_at->format('d.m.Y H:i') !!} Uhr (Europe/Berlin).
