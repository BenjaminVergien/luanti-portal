<?php

namespace Tests\Feature;

use App\Enums\InternalRole;
use App\Enums\MembershipRole;
use App\Filament\Auth\Register;
use App\Livewire\AccessPage;
use App\Mail\PortalInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\PortalAccess;
use DOMDocument;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailLinkSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://portal.example.test:8443';

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('app.url', self::ORIGIN);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** @return array<string, array{string}> */
    public static function panels(): array
    {
        return ['admin' => ['admin'], 'portal' => ['portal']];
    }

    #[DataProvider('panels')]
    public function test_reset_email_is_canonical_before_queueing_and_the_link_resets_the_password(string $panel): void
    {
        config(['auth.passwords.users.expire' => 23]);
        Queue::fake();
        $user = User::factory()->globalAdmin()->create();
        $this->panel($panel);
        Livewire::withHeaders($this->hostileHeaders())->test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])->call('request')->assertHasNoFormErrors();

        $job = $this->queuedNotification(ResetPasswordNotification::class);
        $url = $job->notification->url;
        $this->assertStringStartsWith(self::ORIGIN.'/'.$panel.'/password-reset/reset?', $url);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertQueuedMailIsCanonical($job, $url);

        $this->get($url)->assertOk();
        $this->get($url.'&email=tampered@example.test')->assertForbidden();
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $parameters['token']])
            ->fillForm(['password' => 'New-Secure-Password!123', 'passwordConfirmation' => 'New-Secure-Password!123'])
            ->call('resetPassword')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('New-Secure-Password!123', $user->fresh()->password));
    }

    public function test_registration_verification_email_is_canonical_and_confirms_the_address(): void
    {
        config(['auth.verification.expire' => 31]);
        Queue::fake();
        $this->panel('portal');
        Livewire::withHeaders($this->hostileHeaders())->test(Register::class)->fillForm([
            'name' => 'New User', 'email' => 'new@example.test',
            'password' => 'Secure-Registration!123', 'passwordConfirmation' => 'Secure-Registration!123',
        ])->call('register')->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $job = $this->queuedNotification(VerifyEmail::class);
        $url = $job->notification->url;
        $this->assertStringStartsWith(self::ORIGIN.'/portal/email-verification/verify/', $url);
        $this->assertQueuedMailIsCanonical($job, $url);
        $this->actingAs($user)->get($url.'&extra=tampered')->assertForbidden();
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    #[DataProvider('panels')]
    public function test_expired_password_reset_tokens_are_still_rejected(string $panel): void
    {
        Queue::fake();
        $user = User::factory()->globalAdmin()->create();
        $originalPassword = $user->password;
        $this->panel($panel);
        Livewire::withHeaders($this->hostileHeaders())->test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])->call('request')->assertHasNoFormErrors();
        $job = $this->queuedNotification(ResetPasswordNotification::class);
        parse_str(parse_url($job->notification->url, PHP_URL_QUERY), $parameters);
        $broker = Filament::getAuthPasswordBroker() ?? config('auth.defaults.passwords');
        $this->travel(config('auth.passwords.'.$broker.'.expire') + 1)->minutes();

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $parameters['token']])
            ->fillForm(['password' => 'New-Secure-Password!123', 'passwordConfirmation' => 'New-Secure-Password!123'])
            ->call('resetPassword')->assertNotified(__(Password::INVALID_TOKEN));
        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    public function test_verification_signature_works_through_a_trusted_https_proxy(): void
    {
        $user = User::factory()->unverified()->create();
        $this->panel('portal');
        $url = Filament::getVerifyEmailUrl($user);
        TrustProxies::at(['127.0.0.1']);

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'portal.example.test',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '8443',
            ])->get('http://portal.example.test'.parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY))
            ->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_resent_verification_email_is_canonical_and_expires(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $this->panel('portal');
        Livewire::withHeaders($this->hostileHeaders())->test(EmailVerificationPrompt::class)
            ->callAction('resendNotification')->assertHasNoActionErrors();

        $job = $this->queuedNotification(VerifyEmail::class);
        $url = $job->notification->url;
        $this->assertStringStartsWith(self::ORIGIN.'/portal/email-verification/verify/', $url);
        $this->assertQueuedMailIsCanonical($job, $url);
        $this->travel(config('auth.verification.expire', 60) + 1)->minutes();
        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /** @return array<string, array{bool}> */
    public static function invitationKinds(): array
    {
        return ['organization' => [true], 'internal' => [false]];
    }

    #[DataProvider('invitationKinds')]
    public function test_invitation_mail_parts_use_the_canonical_url_and_acceptance_still_works(bool $organizationInvitation): void
    {
        $mailer = Mail::getFacadeRoot()->mailer('array');
        Mail::fake();
        $actor = User::factory()->globalAdmin()->create();
        $recipient = User::factory()->create();
        $organization = $organizationInvitation ? Organization::factory()->create() : null;
        URL::setRequest(Request::create('http://attacker.example:9123/'));
        $invitation = app(PortalAccess::class)->invite(
            $actor, $recipient->email, $organization,
            $organizationInvitation ? MembershipRole::Staff : null,
            $organizationInvitation ? null : InternalRole::Caseworker,
        );
        Mail::assertSent(PortalInvitation::class, 1);
        $mail = Mail::sent(PortalInvitation::class)->sole();
        $this->assertTrue($mail->hasTo($recipient->email));
        $this->assertStringStartsWith(self::ORIGIN.'/einladungen/', $mail->acceptUrl);
        $message = $mailer->send($mail)->getOriginalMessage();
        $this->assertMailLinksAreCanonical($message, $mail->acceptUrl);

        $this->actingAs($recipient)->get($mail->acceptUrl)->assertRedirect(route('access'));
        $this->panel('portal');
        Livewire::test(AccessPage::class)->call('acceptInvitation')->assertHasNoErrors();
        $this->assertSame($recipient->id, $invitation->fresh()->accepted_by);
        if ($organizationInvitation) {
            $this->assertTrue($recipient->fresh()->canAccessTenant($organization));
        } else {
            $this->assertSame(InternalRole::Caseworker, $recipient->fresh()->internal_role);
        }
    }

    public function test_smtp_transport_requires_tls_and_has_a_bounded_timeout_without_connecting(): void
    {
        config(['mail.mailers.smtp.url' => null, 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587]);
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertInstanceOf(SocketStream::class, $transport->getStream());
        $this->assertSame(15.0, $transport->getStream()->getTimeout());
    }

    public function test_loopback_postfix_can_explicitly_disable_tls_without_connecting(): void
    {
        config(['mail.mailers.smtp.url' => null, 'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 25,
            'mail.mailers.smtp.auto_tls' => false, 'mail.mailers.smtp.require_tls' => false]);
        $transport = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertFalse($transport->isAutoTls());
        $this->assertFalse($transport->isTlsRequired());
        $this->assertFalse($transport->getStream()->isTLS());
        $this->assertSame('127.0.0.1', $transport->getStream()->getHost());
    }

    private function panel(string $id): void
    {
        Filament::setCurrentPanel(Filament::getPanel($id));
        Filament::bootCurrentPanel();
        Filament::setTenant(null);
    }

    /** @return array<string, string> */
    private function hostileHeaders(): array
    {
        return [
            'Host' => 'attacker.example:9123', 'X-Forwarded-Host' => 'forwarded.attacker.example',
            'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '9123', 'X-Forwarded-Prefix' => '/attacker',
        ];
    }

    /** @param class-string<ResetPasswordNotification|VerifyEmail> $notification */
    private function queuedNotification(string $notification): SendQueuedNotifications
    {
        Queue::assertPushed(SendQueuedNotifications::class, 1);
        $job = Queue::pushed(SendQueuedNotifications::class)->sole();
        $this->assertInstanceOf($notification, $job->notification);

        return $job;
    }

    private function assertQueuedMailIsCanonical(SendQueuedNotifications $job, string $url): void
    {
        $restoredJob = unserialize(serialize($job));
        URL::setRequest(Request::create('http://worker.attacker.example:9123/'));
        $restoredJob->handle(app(ChannelManager::class));
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertMailLinksAreCanonical($message, $url);
        $isVerification = $job->notification instanceof VerifyEmail;
        $this->assertSame($isVerification ? 'E-Mail-Adresse bestätigen · Luanti-Portal' : 'Passwort zurücksetzen · Luanti-Portal', $message->getSubject());
        $minutes = $isVerification ? config('auth.verification.expire', 60) : config('auth.passwords.users.expire');
        foreach ([$message->getHtmlBody(), $message->getTextBody()] as $body) {
            $this->assertStringContainsString('Luanti-Portal', $body);
            $this->assertStringContainsString('ab Anforderung '.$minutes.' Minuten gültig', $body);
            $this->assertStringContainsString($isVerification ? 'Du hast kein Konto angelegt?' : 'Du hast kein neues Passwort angefordert?', $body);
            $this->assertStringNotContainsString('Hello!', $body);
            $this->assertStringNotContainsString('Regards,', $body);
        }
        $this->assertStringContainsString('Funktioniert der Button nicht?', $message->getHtmlBody());
    }

    private function assertMailLinksAreCanonical(Email $message, string $url): void
    {
        $this->assertNotNull($message->getHtmlBody());
        $this->assertNotNull($message->getTextBody());
        $document = new DOMDocument;
        $document->loadHTML($message->getHtmlBody());
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            $links[] = $link->getAttribute('href');
            foreach ([PHP_URL_SCHEME, PHP_URL_HOST, PHP_URL_PORT] as $component) {
                $this->assertSame(parse_url(self::ORIGIN, $component), parse_url($link->getAttribute('href'), $component));
            }
        }
        $this->assertContains($url, $links);
        $this->assertStringContainsString($url, $message->getTextBody());
        $this->assertStringNotContainsString('attacker.example', $message->getHtmlBody());
        $this->assertStringNotContainsString('attacker.example', $message->getTextBody());
    }
}
