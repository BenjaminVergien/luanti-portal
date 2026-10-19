<x-mail-layout heading="Einladung zum Luanti-Portal" action-label="Einladung öffnen" :action-url="$acceptUrl">
    @if($invitation->organization)
        <p style="margin: 0 0 20px;">Du wurdest zur Einrichtung <strong style="white-space: pre-wrap;">{{ $invitation->organization->name }}</strong> als <strong>{{ $invitation->membership_role->label() }}</strong> eingeladen.</p>
    @else
        <p style="margin: 0 0 20px;">Du wurdest als <strong>{{ $invitation->internal_role->label() }}</strong> eingeladen.</p>
    @endif
    <p style="margin: 0 0 20px;">Diese Einladung ist für {{ $invitation->email }} bestimmt.</p>
    <p style="margin: 0 0 20px;">Wenn du noch kein Konto im Luanti-Portal hast, klicke auf „Einladung öffnen“ und registriere dich zunächst mit der E-Mail-Adresse, an die diese Einladung gesendet wurde. Bestätige anschließend deine E-Mail-Adresse über die Bestätigungs-E-Mail. Danach kannst du die Einladung im Portal annehmen.</p>
    <p style="margin: 0;">Wenn du bereits ein Konto im Luanti-Portal besitzt, klicke auf „Einladung öffnen“ und melde dich mit der eingeladenen E-Mail-Adresse an, um die Einladung anzunehmen. Falls deine E-Mail-Adresse noch nicht bestätigt ist, bestätige sie bitte zuerst.</p>
    <x-slot name="footer">
        <p style="margin: 0 0 16px;">Die Einladung gilt bis {{ $invitation->expires_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr (Europe/Berlin).</p>
        <p style="margin: 0; color: #6b7280; font-size: 14px;">Du erwartest keine Einladung? Dann kannst du diese E-Mail ignorieren. Ohne deine Annahme wird keine Mitgliedschaft oder interne Rolle vergeben.</p>
    </x-slot>
</x-mail-layout>
