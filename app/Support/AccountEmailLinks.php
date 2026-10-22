<?php

namespace App\Support;

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
        $url = new UrlGenerator(app('router')->getRoutes(), Request::create($origin));
        $url->forceRootUrl($origin);
        $url->setKeyResolver(fn () => config('app.key'));

        return $url;
    }

    public function reset($user, string $token): string
    {
        return $this->generator()->route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
    }

    public function verify($user): string
    {
        return $this->generator()->temporarySignedRoute('verification.verify', now()->addMinutes(config('auth.verification.expire', 60)), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
    }

    public function message(string $type, string $url): MailMessage
    {
        $reset = $type === 'reset';
        $minutes = $reset ? config('auth.passwords.'.config('auth.defaults.passwords').'.expire') : config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject($reset ? 'Reset your TaskBridge password' : 'Verify your TaskBridge email')
            ->action($reset ? 'Reset password' : 'Verify email address', $url)
            ->markdown('emails.account', [
                'homeUrl' => $this->generator()->to('/'),
                'heading' => $reset ? 'Reset your password' : 'Verify your email address',
                'introduction' => $reset ? 'A password reset was requested for your TaskBridge account.' : 'You requested verification of your current TaskBridge email address. Verification is optional.',
                'expiry' => "This link expires in {$minutes} minutes.",
                'guidance' => $reset ? 'If you did not request this, you can ignore this email. Your password will stay unchanged.' : 'If you did not request this, ignore this email. No action is needed.',
            ]);
    }
}
