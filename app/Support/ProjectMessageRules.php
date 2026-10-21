<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProjectMessageRules
{
    public static function body(array $input): string
    {
        $body = $input['body'] ?? null;
        if (is_string($body)) {
            $body = Str::trim(str_replace(["\r\n", "\r"], "\n", $body));
        }

        return Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:5000', 'not_regex:/\A[\s\p{Z}\p{C}]*\z/u']])->validate()['body'];
    }

    public static function token(string $token): string
    {
        return Validator::make(['submission_id' => Str::lower($token)], ['submission_id' => ['required', 'uuid']])->validate()['submission_id'];
    }
}
