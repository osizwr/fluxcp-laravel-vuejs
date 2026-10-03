<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Contracts\ChallengesHumanity;
use App\Services\Captcha\NativeCaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRathena;
use Tests\TestCase;

/**
 * The CAPTCHA, and the registration form that uses it.
 *
 * Ports the coverage for modules/captcha/index.php and the UseCaptcha branch
 * of Flux_LoginServer::register().
 */
final class CaptchaTest extends TestCase
{
    use InteractsWithRathena;
    use RefreshDatabase;

    private const PASSWORD = 'Str0ngPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set('panel.captcha.driver', 'native');
        config()->set('panel.captcha.on_registration', true);
        config()->set('panel.registration.enabled', true);
        config()->set('panel.registration.require_email_confirmation', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'username' => 'newplayer',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'email' => 'newplayer@example.com',
            'email_confirmation' => 'newplayer@example.com',
            'gender' => 'M',
            'birthdate' => '1995-04-12',
            ...$overrides,
        ];
    }

    /**
     * Issue a challenge in the session the test requests share, and return the
     * answer.
     *
     * The answer is generated here rather than read out of the image, because
     * reading it back would mean solving our own CAPTCHA.
     */
    private function issueChallenge(): string
    {
        $this->get('/api/captcha')->assertOk();

        /*
         * The session holds a digest, not the answer, so the answer cannot be
         * recovered from it. Instead the challenge is replaced with one whose
         * answer is known, through the same code path a real one uses.
         */
        $answer = 'ABCDE';

        session()->put('captcha.pending', [
            'digest' => hash('sha256', $answer),
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return $answer;
    }

    /*
    |--------------------------------------------------------------------------
    | The image
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_endpoint_serves_a_png(): void
    {
        $response = $this->get('/api/captcha');

        $response->assertOk()->assertHeader('Content-Type', 'image/png');

        $body = $response->getContent();

        $this->assertNotSame('', $body);
        // The PNG magic number, so this is an actual image and not an error
        // page served with the wrong content type.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($body, 0, 8));
    }

    #[Test]
    public function the_image_is_never_cached(): void
    {
        /*
         * A cached challenge is one image answered many times, which is the
         * same as no challenge at all.
         */
        $response = $this->get('/api/captcha');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function each_request_issues_a_different_challenge(): void
    {
        $first = $this->get('/api/captcha');
        $firstDigest = session('captcha.pending.digest');

        $this->get('/api/captcha');
        $secondDigest = session('captcha.pending.digest');

        $this->assertNotSame($firstDigest, $secondDigest);
        $first->assertOk();
    }

    #[Test]
    public function the_session_holds_a_digest_rather_than_the_answer(): void
    {
        $this->get('/api/captcha')->assertOk();

        $digest = (string) session('captcha.pending.digest');

        // A SHA-256, so a dump of the session store does not reveal answers.
        $this->assertSame(64, strlen($digest));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
    }

    #[Test]
    public function the_image_is_not_served_when_recaptcha_is_configured(): void
    {
        config()->set('panel.captcha.driver', 'recaptcha');
        config()->set('panel.captcha.recaptcha.secret_key', 'not-a-real-key');

        $this->app->forgetInstance(ChallengesHumanity::class);

        $this->get('/api/captcha')->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Answering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function registration_requires_the_challenge_when_it_is_on(): void
    {
        $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('captcha');
    }

    #[Test]
    public function the_right_answer_is_accepted(): void
    {
        $answer = $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload(['captcha' => $answer]))
            ->assertCreated();
    }

    #[Test]
    public function the_answer_is_not_case_sensitive(): void
    {
        /*
         * Reading case off a distorted image is guesswork, and the legacy
         * panel compared with strtolower() on both sides too.
         */
        $answer = $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload(['captcha' => strtolower($answer)]))
            ->assertCreated();
    }

    #[Test]
    public function a_wrong_answer_is_refused(): void
    {
        $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload(['captcha' => 'ZZZZZ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('captcha');

        $this->assertDatabaseCount('login', 0, $this->serverGroup()->loginConnection());
    }

    #[Test]
    public function a_wrong_answer_consumes_the_challenge(): void
    {
        $answer = $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload(['captcha' => 'ZZZZZ']))
            ->assertStatus(422);

        /*
         * The right answer no longer works, because the challenge is gone.
         * Without this an attacker could brute-force one image.
         */
        $this->postJson('/api/auth/register', $this->payload(['captcha' => $answer]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('captcha');

        $this->assertNull(session('captcha.pending'));
    }

    #[Test]
    public function a_correct_answer_cannot_be_replayed(): void
    {
        $answer = $this->issueChallenge();

        $this->postJson('/api/auth/register', $this->payload(['captcha' => $answer]))
            ->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/register', $this->payload([
            'username' => 'second',
            'email' => 'second@example.com',
            'email_confirmation' => 'second@example.com',
            'captcha' => $answer,
        ]))->assertStatus(422)->assertJsonValidationErrors('captcha');
    }

    #[Test]
    public function an_expired_challenge_is_refused(): void
    {
        $this->get('/api/captcha')->assertOk();

        session()->put('captcha.pending', [
            'digest' => hash('sha256', 'ABCDE'),
            'expires_at' => now()->subMinute()->getTimestamp(),
        ]);

        $this->postJson('/api/auth/register', $this->payload(['captcha' => 'ABCDE']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('captcha');
    }

    #[Test]
    public function registering_with_no_challenge_issued_is_refused(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['captcha' => 'ABCDE']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('captcha');
    }

    #[Test]
    public function the_challenge_is_skipped_when_it_is_off(): void
    {
        config()->set('panel.captcha.on_registration', false);

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | The generator
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_generated_answer_avoids_ambiguous_characters(): void
    {
        /*
         * 0/O and 1/I/L are indistinguishable once the glyph is rotated and
         * scaled, and a challenge somebody cannot read stops registrations
         * rather than robots.
         */
        $captcha = $this->app->make(NativeCaptcha::class);

        for ($i = 0; $i < 25; $i++) {
            $captcha->issue();

            $this->assertTrue($captcha->hasOutstandingChallenge());
        }

        $alphabet = (string) config('panel.captcha.native.characters');

        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertStringNotContainsString($ambiguous, $alphabet);
        }
    }

    #[Test]
    public function verifying_reports_no_outstanding_challenge_afterwards(): void
    {
        $captcha = $this->app->make(NativeCaptcha::class);

        $captcha->issue();
        $this->assertTrue($captcha->hasOutstandingChallenge());

        $captcha->verify('definitely-wrong', '127.0.0.1');

        $this->assertFalse($captcha->hasOutstandingChallenge());
    }
}
