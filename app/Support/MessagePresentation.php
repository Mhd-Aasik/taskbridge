<?php

namespace App\Support;

use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MessagePresentation
{
    public const TTL = 1200;

    public const WINDOW = 90;

    /** Only call with the complete, contiguous ID window actually included in the rendered view. */
    public static function issue(User $user, ProjectAssignment $assignment, array $ids): ?string
    {
        if ($ids === []) {
            return null;
        }

        return Crypt::encryptString(json_encode(['user' => $user->id, 'assignment' => $assignment->id, 'ids' => array_values($ids), 'issued' => now()->timestamp], JSON_THROW_ON_ERROR));
    }

    public static function verify(string $token, User $user, ProjectAssignment $assignment): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['read' => 'This displayed-message receipt is invalid. Refresh the conversation.']);
        }
        $validator = Validator::make(is_array($data) ? $data : [], ['user' => ['required', 'integer'], 'assignment' => ['required', 'integer'],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::WINDOW], 'ids.*' => ['required', 'integer', 'min:1', 'distinct:strict'], 'issued' => ['required', 'integer']]);
        if ($validator->fails() || ($data['user'] ?? null) !== $user->id || ($data['assignment'] ?? null) !== $assignment->id
            || $data['issued'] > now()->timestamp || $data['issued'] < now()->timestamp - self::TTL) {
            throw ValidationException::withMessages(['read' => 'This displayed-message receipt is invalid or expired. Refresh the conversation.']);
        }
        $ids = $data['ids'];
        $actual = $assignment->messages()->whereBetween('id', [min($ids), max($ids)])->pluck('id')->all();
        if ($actual !== $ids) {
            throw ValidationException::withMessages(['read' => 'Only messages displayed from this conversation may be marked read.']);
        }

        return $ids;
    }
}
