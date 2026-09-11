<?php

namespace App\Modules\InternalExams;

use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * @phpstan-type TokenRow object{id:mixed,organization_id:mixed,internal_exam_access_id:mixed,internal_exam_attempt_id:mixed,purpose:mixed,token_sequence:mixed,lookup_id:mixed,secret_verifier:mixed,verifier_key_version:mixed,issued_at:mixed,expires_at:mixed,revoked_at:mixed,revoke_reason_code:mixed}
 * @phpstan-type TokenContext array{token_id:string,organization_id:string,access_id:string,attempt_id:string,purpose:string,expires_at:string}
 */
final class InternalExamTokenService
{
    /** @return array{raw_token:string,token_id:string,expires_at:string} */
    public function issueExecution(
        string $organizationId,
        string $accessId,
        string $attemptId,
        ?string $issuedByUserId,
        CarbonImmutable $issuedAt,
        CarbonImmutable $accessExpiresAt,
    ): array {
        return $this->issue(
            $organizationId,
            $accessId,
            $attemptId,
            'exam_execution',
            $issuedByUserId,
            $issuedAt,
            $this->ttlMinutes('execution_token_ttl_minutes'),
            $accessExpiresAt,
        );
    }

    /** @return array{raw_token:string,token_id:string,expires_at:string} */
    public function rotateExecution(
        string $organizationId,
        string $accessId,
        string $attemptId,
        ?string $issuedByUserId,
        CarbonImmutable $issuedAt,
        CarbonImmutable $accessExpiresAt,
        string $reasonCode,
    ): array {
        $this->assertTransaction();
        $this->revokeCurrent($organizationId, $accessId, 'exam_execution', $reasonCode, $issuedAt);

        return $this->issueExecution(
            $organizationId,
            $accessId,
            $attemptId,
            $issuedByUserId,
            $issuedAt,
            $accessExpiresAt,
        );
    }

    public function revokeExecution(
        string $organizationId,
        string $accessId,
        string $reasonCode,
        CarbonImmutable $revokedAt,
    ): void {
        $this->assertTransaction();
        $this->revokeCurrent($organizationId, $accessId, 'exam_execution', $reasonCode, $revokedAt);
    }

    /** @return TokenContext */
    public function verify(string $rawToken, string $expectedPurpose, ?CarbonImmutable $effectiveAt = null): array
    {
        [$lookupId, $secret] = $this->parse($rawToken);

        /** @var TokenRow|null $row */
        $row = DB::table('internal_exam_access_tokens')->where('lookup_id', $lookupId)->first();
        if ($row === null || (string) $row->purpose !== $expectedPurpose) {
            throw $this->invalid();
        }
        $keyVersion = (int) $row->verifier_key_version;
        $computed = hash_hmac('sha256', $secret, $this->verifierKey($keyVersion));
        if (! hash_equals((string) $row->secret_verifier, $computed)) {
            throw $this->invalid();
        }

        $effectiveAt ??= CarbonImmutable::now();
        if ($row->revoked_at !== null || ! $effectiveAt->lt(CarbonImmutable::parse((string) $row->expires_at))) {
            throw $this->invalid();
        }

        return [
            'token_id' => (string) $row->id,
            'organization_id' => (string) $row->organization_id,
            'access_id' => (string) $row->internal_exam_access_id,
            'attempt_id' => (string) $row->internal_exam_attempt_id,
            'purpose' => (string) $row->purpose,
            'expires_at' => (string) $row->expires_at,
        ];
    }

    /**
     * @return array{raw_token:string,token_id:string,expires_at:string}
     */
    private function issue(
        string $organizationId,
        string $accessId,
        string $attemptId,
        string $purpose,
        ?string $issuedByUserId,
        CarbonImmutable $issuedAt,
        int $ttlMinutes,
        ?CarbonImmutable $notAfter,
    ): array {
        $this->assertTransaction();

        $access = DB::table('internal_exam_accesses')
            ->where('organization_id', $organizationId)
            ->where('id', $accessId)
            ->where('internal_exam_attempt_id', $attemptId)
            ->lockForUpdate()
            ->first();
        if ($access === null) {
            throw ResourceDomainException::notFound('Internal exam access not found.');
        }
        if (DB::table('internal_exam_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('internal_exam_access_id', $accessId)
            ->where('purpose', $purpose)
            ->whereNull('revoked_at')
            ->exists()) {
            throw ResourceDomainException::conflict('Access already has a current token for this purpose.');
        }

        $expiresAt = $issuedAt->addMinutes($ttlMinutes);
        if ($notAfter !== null && $notAfter->lt($expiresAt)) {
            $expiresAt = $notAfter;
        }
        if (! $issuedAt->lt($expiresAt)) {
            throw ResourceDomainException::conflict('Token expiry boundary is not in the future.');
        }

        $sequence = (int) DB::table('internal_exam_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('internal_exam_access_id', $accessId)
            ->where('purpose', $purpose)
            ->max('token_sequence') + 1;

        $lookupId = (string) Str::uuid();
        $secret = $this->base64Url(random_bytes(32));
        $rawToken = $lookupId.'.'.$secret;
        $tokenId = (string) Str::uuid7();
        $keyVersion = 1;

        DB::table('internal_exam_access_tokens')->insert([
            'id' => $tokenId,
            'organization_id' => $organizationId,
            'internal_exam_access_id' => $accessId,
            'internal_exam_attempt_id' => $attemptId,
            'purpose' => $purpose,
            'token_sequence' => $sequence,
            'lookup_id' => $lookupId,
            'secret_verifier' => hash_hmac('sha256', $secret, $this->verifierKey($keyVersion)),
            'verifier_key_version' => $keyVersion,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'revoke_reason_code' => null,
            'issued_by_user_id' => $issuedByUserId,
            'created_at' => $issuedAt,
        ]);

        return [
            'raw_token' => $rawToken,
            'token_id' => $tokenId,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function revokeCurrent(
        string $organizationId,
        string $accessId,
        string $purpose,
        string $reasonCode,
        CarbonImmutable $revokedAt,
    ): void {
        if (trim($reasonCode) === '') {
            throw new LogicException('Token revocation reason code is required.');
        }

        /** @var TokenRow|null $current */
        $current = DB::table('internal_exam_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('internal_exam_access_id', $accessId)
            ->where('purpose', $purpose)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();

        if ($current === null) {
            return;
        }

        DB::table('internal_exam_access_tokens')->where('id', $current->id)->update([
            'revoked_at' => $revokedAt,
            'revoke_reason_code' => $reasonCode,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function parse(string $rawToken): array
    {
        $parts = explode('.', trim($rawToken), 2);
        if (count($parts) !== 2 || ! Str::isUuid($parts[0]) || strlen($parts[1]) < 43) {
            throw $this->invalid();
        }

        return [$parts[0], $parts[1]];
    }

    private function verifierKey(int $version): string
    {
        if ($version !== 1) {
            throw new LogicException('Unsupported internal exam token verifier key version.');
        }
        $key = config('internal_exams.token_verifier_key_v1');
        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('INTERNAL_EXAM_TOKEN_VERIFIER_KEY_V1 must contain at least 32 bytes.');
        }

        return $key;
    }

    private function ttlMinutes(string $configKey): int
    {
        $value = config('internal_exams.'.$configKey);
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new LogicException('Internal exam token TTL must be explicitly configured.');
        }
        $ttl = (int) $value;
        if ($ttl < 1) {
            throw new LogicException('Internal exam token TTL must be positive.');
        }

        return $ttl;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Internal exam token mutation must run inside the business transaction.');
        }
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function invalid(): ResourceDomainException
    {
        return new ResourceDomainException('INVALID_EXAM_ACCESS_TOKEN', 401, 'Invalid or expired internal exam access token.');
    }
}
