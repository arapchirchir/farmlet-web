<?php

namespace App\Services;

use App\Models\County;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActorUniqueIdService
{
    public const ROLE_VENDOR = 'vendor';

    public const ROLE_FARMER = 'farmer';

    public const ROLE_DRIVER = 'driver';

    public static function assign(User $user, string $actorRole, ?int $countyId = null, bool $forceRegenerate = false): ?string
    {
        if (! in_array($actorRole, self::supportedRoles(), true)) {
            return null;
        }

        $countyId = $countyId ?? $user->county_id;
        if (! $countyId) {
            return null;
        }

        $county = County::find($countyId);
        if (! $county) {
            return null;
        }

        $countyCode = self::normalizeCountyCode($county->county_code, $county->id);
        $countyShortCode = self::normalizeCountyShortCode($county->county_short_code, $county->name);

        if (
            ! $forceRegenerate
            && $user->actor_unique_id
            && $user->actor_unique_role === $actorRole
            && (int) $user->county_id === (int) $countyId
            && self::matchesFormat($user->actor_unique_id, $countyCode, $countyShortCode)
        ) {
            return $user->actor_unique_id;
        }

        return DB::transaction(function () use ($user, $actorRole, $countyId, $countyCode, $countyShortCode, $forceRegenerate) {
            $freshUser = User::query()->lockForUpdate()->find($user->id);
            if (! $freshUser) {
                return null;
            }

            if (
                ! $forceRegenerate
                && $freshUser->actor_unique_id
                && $freshUser->actor_unique_role === $actorRole
                && (int) $freshUser->county_id === (int) $countyId
                && self::matchesFormat($freshUser->actor_unique_id, $countyCode, $countyShortCode)
            ) {
                return $freshUser->actor_unique_id;
            }

            $nextSequence = ((int) User::query()
                ->where('county_id', $countyId)
                ->where('actor_unique_role', $actorRole)
                ->lockForUpdate()
                ->max('actor_county_sequence')) + 1;

            $identifier = self::formatIdentifier($countyCode, $nextSequence, $countyShortCode);

            while (
                User::query()
                    ->where('actor_unique_id', $identifier)
                    ->where('id', '!=', $freshUser->id)
                    ->exists()
            ) {
                $nextSequence++;
                $identifier = self::formatIdentifier($countyCode, $nextSequence, $countyShortCode);
            }

            $freshUser->update([
                'actor_unique_id' => $identifier,
                'actor_unique_role' => $actorRole,
                'actor_county_sequence' => $nextSequence,
            ]);

            $user->forceFill([
                'actor_unique_id' => $identifier,
                'actor_unique_role' => $actorRole,
                'actor_county_sequence' => $nextSequence,
            ]);

            return $identifier;
        }, 3);
    }

    public static function supportedRoles(): array
    {
        return [
            self::ROLE_VENDOR,
            self::ROLE_FARMER,
            self::ROLE_DRIVER,
        ];
    }

    private static function formatIdentifier(string $countyCode, int $sequence, string $countyShortCode): string
    {
        return sprintf('%s-%03d-%s', $countyCode, $sequence, $countyShortCode);
    }

    private static function matchesFormat(string $identifier, string $countyCode, string $countyShortCode): bool
    {
        return Str::startsWith($identifier, $countyCode.'-')
            && Str::endsWith($identifier, '-'.$countyShortCode)
            && preg_match('/^\d{3}-\d{3}-[A-Z0-9]+$/', $identifier) === 1;
    }

    private static function normalizeCountyCode(?string $countyCode, int $fallbackCountyId): string
    {
        $digits = preg_replace('/\D+/', '', (string) $countyCode);
        if ($digits === '') {
            $digits = (string) $fallbackCountyId;
        }

        return str_pad(substr($digits, -3), 3, '0', STR_PAD_LEFT);
    }

    private static function normalizeCountyShortCode(?string $countyShortCode, string $fallbackName): string
    {
        $codeSource = $countyShortCode ?: $fallbackName;
        $lettersAndNumbers = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeSource));
        $shortCode = substr($lettersAndNumbers, 0, 8);

        if ($shortCode === '') {
            return 'COUNTY';
        }

        return $shortCode;
    }
}
