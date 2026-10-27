<?php

namespace App\Support;

use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\UrlGenerator;
use InvalidArgumentException;

class AccountEmailLinks
{
    public function generator(): UrlGenerator
    {
        $origin = config('app.url');
        $parts = parse_url($origin);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Configure a trusted HTTP(S) APP_URL for account emails.');
        }
        if (app()->environment('production') && $parts['scheme'] !== 'https') {
            throw new InvalidArgumentException('Production account emails require a trusted HTTPS APP_URL.');
        }
        $url = new UrlGenerator(app('router')->getRoutes(), Request::create($origin));
        $url->forceRootUrl($origin);
        $url->setKeyResolver(fn () => config('app.key'));

        return $url;
    }

    public function reset($user, #[\SensitiveParameter] string $token): string
    {
        return $this->generator()->route('password.reset').'#'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
    }

    public function setup(PendingRegistration $registration, #[\SensitiveParameter] string $token): string
    {
        return $this->generator()->route('registration.setup', ['registration' => $registration->id]).'#'.http_build_query(['token' => $token]);
    }

    public function verify($user): string
    {
        return $this->generator()->temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
    }

    public function verificationAfterLogin(User $user, #[\SensitiveParameter] mixed $intended): ?string
    {
        if (! is_string($intended) || strlen($intended) > 4096) {
            return null;
        }
        $generator = $this->generator();
        $expected = $generator->route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]);
        // Only this user's exact canonical verification route may survive login.
        // Other intended destinations, hosts and identities remain discarded.
        if (! hash_equals($expected, explode('?', $intended, 2)[0]) || parse_url($intended, PHP_URL_FRAGMENT) !== null) {
            return null;
        }
        try {
            $request = Request::create($intended);
            $keys = array_keys($request->query());
            sort($keys);
            $signature = $request->query('signature');
            $expires = $request->query('expires');

            return $keys === ['expires', 'signature'] && is_string($expires) && ctype_digit($expires)
                && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
                && $generator->hasValidSignature($request) ? $intended : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function message(string $type, #[\SensitiveParameter] string $url): MailMessage
    {
        $reset = $type === 'reset';
        $setup = $type === 'setup';
        $minutes = $setup ? config('auth.registration.expire', 60) : ($reset ? config('auth.passwords.'.config('auth.defaults.passwords').'.expire') : config('auth.verification.expire', 60));

        return (new MailMessage)
            ->subject($setup ? 'Finish setting up your TaskBridge account' : ($reset ? 'Reset your TaskBridge password' : 'Verify your TaskBridge email'))
            ->action($setup ? 'Choose your password' : ($reset ? 'Reset password' : 'Verify email address'), $url)
            ->markdown('emails.account', [
                'homeUrl' => $this->generator()->to('/'),
                'heading' => $setup ? 'Welcome to TaskBridge' : ($reset ? 'Reset your password' : 'Verify your email address'),
                'introduction' => $setup ? 'Finish your Client or Freelancer registration by choosing a password. Your email is verified only when you complete this secure form.' : ($reset ? 'A password reset was requested for your TaskBridge account.' : 'Verify ownership of your current email address before accessing your TaskBridge workspace.'),
                'expiry' => "This link expires in {$minutes} minutes.",
                'guidance' => $reset ? 'If you did not request this, you can ignore this email. Your password will stay unchanged.' : 'If you did not request this, ignore this email. No action is needed.',
            ]);
    }
}
