<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Mail\PortalInvitation;
use App\Models\Invitation;
use App\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PortalInvitationTest extends TestCase
{
    public static function organizationNames(): array
    {
        return [
            'ordinary name' => ['Kinder & Jugend „Miteinander“'],
            'markdown link' => ['[Support](https://attacker.example/support)'],
            'markdown image' => ['![Logo](https://attacker.example/tracking.png)'],
            'html link' => ['<a href="https://attacker.example/support">Support</a>'],
            'html image' => ['<img src="https://attacker.example/tracking.png" onerror="alert(1)">'],
            'script' => ['<script>alert("Support")</script>'],
            'emphasis' => ['**Einrichtung** _Support_ ~~Name~~'],
            'backslashes' => ['\\[Support](https://attacker.example/support) \\ **Name**'],
            'newlines' => ["Erste Zeile\n\n[Support](https://attacker.example/support)\nLetzte Zeile"],
            'headings and separators' => ["Einrichtung\n\n---\n\n# Support"],
            'html entities' => ['&lt;img src=&quot;https://attacker.example/tracking.png&quot;&gt;'],
        ];
    }

    #[DataProvider('organizationNames')]
    public function test_organization_names_remain_literal_text_in_both_mail_parts(string $name): void
    {
        $organization = Organization::factory()->make(['name' => $name]);
        $invitation = Invitation::factory()->make(['organization_id' => null, 'invited_by' => null]);
        $invitation->setRelation('organization', $organization);
        $url = route('invitations.open', ['token' => str_repeat('a', 64)]);

        $message = $this->renderMail($invitation, $url);
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        $this->assertNotNull($html);
        $this->assertNotNull($text);
        $document = new DOMDocument;
        $document->loadHTML($html);
        $xpath = new DOMXPath($document);

        $this->assertSame(0, $xpath->query('//img | //script | //iframe | //object | //form')->length);
        $this->assertSame(1, $xpath->query('//a')->length);
        $this->assertSame($url, $xpath->query('//a')->item(0)->getAttribute('href'));
        $this->assertSame('Einladung öffnen', $xpath->query('//a')->item(0)->textContent);
        $this->assertSame(2, $xpath->query('//strong')->length);
        $this->assertSame($name, $xpath->query('//strong')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//em | //del | //hr | //h2 | //h3')->length);
        $this->assertStringContainsString($name, $text);
        $this->assertStringContainsString($url, $text);
        $this->assertSame($name, $organization->name);
        $this->assertSame('Einladung zum Luanti-Portal', $message->getSubject());
    }

    public static function membershipRoles(): array
    {
        return [
            'staff' => [MembershipRole::Staff, 'Fachkraft'],
            'manager' => [MembershipRole::Manager, 'Einrichtungsverwaltung'],
        ];
    }

    #[DataProvider('membershipRoles')]
    public function test_organization_invitation_keeps_german_content_and_expiry(MembershipRole $role, string $label): void
    {
        $organization = Organization::factory()->make(['name' => 'Jugendtreff Sonnenschein']);
        $invitation = Invitation::factory()->make([
            'organization_id' => null, 'invited_by' => null, 'membership_role' => $role,
            'expires_at' => '2026-10-16 18:30:00',
        ]);
        $invitation->setRelation('organization', $organization);
        $mail = new PortalInvitation($invitation, route('invitations.open', ['token' => str_repeat('b', 64)]));

        $mail->assertSeeInHtml($organization->name);
        $mail->assertSeeInHtml($label);
        $mail->assertSeeInText("Du wurdest zur Einrichtung {$organization->name} als {$label} eingeladen.");
        foreach (['Melde dich mit der eingeladenen E-Mail-Adresse an', 'Bestätige anschließend deine E-Mail-Adresse', '16.10.2026 18:30 Uhr (Europe/Berlin).'] as $content) {
            $mail->assertSeeInHtml($content);
            $mail->assertSeeInText($content);
        }
    }

    public static function internalRoles(): array
    {
        return [
            'caseworker' => [InternalRole::Caseworker, 'Sachbearbeitung'],
            'global admin' => [InternalRole::GlobalAdmin, 'Globaladmin'],
        ];
    }

    #[DataProvider('internalRoles')]
    public function test_internal_role_invitation_has_html_and_plain_text(InternalRole $role, string $label): void
    {
        $invitation = Invitation::factory()->make([
            'organization_id' => null, 'invited_by' => null, 'membership_role' => null, 'internal_role' => $role,
        ]);
        $invitation->setRelation('organization', null);
        $url = route('invitations.open', ['token' => str_repeat('c', 64)]);

        $message = $this->renderMail($invitation, $url);

        $this->assertStringContainsString($label, $message->getHtmlBody());
        $this->assertStringContainsString("Du wurdest als {$label} eingeladen.", $message->getTextBody());
        $this->assertStringContainsString($url, $message->getHtmlBody());
        $this->assertStringContainsString($url, $message->getTextBody());
        $this->assertStringNotContainsString('zur Einrichtung', $message->getTextBody());
    }

    private function renderMail(Invitation $invitation, string $url): Email
    {
        $sent = Mail::mailer('array')->send((new PortalInvitation($invitation, $url))->to('recipient@example.test'));
        $this->assertNotNull($sent);
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }
}
