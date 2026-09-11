<?php

namespace App\Modules\InternalExams;

use App\Modules\ResourcesCore\ResourceDomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * @phpstan-type CredentialRow object{id:mixed,organization_id:mixed,exam_station_id:mixed,credential_sequence:mixed,lookup_id:mixed,secret_verifier:mixed,verifier_key_version:mixed,issued_at:mixed,revoked_at:mixed,revoke_reason_code:mixed,issued_by_user_id:mixed}
 * @phpstan-type StationCredentialContext array{credential_id:string,organization_id:string,station_id:string,credential_sequence:int,authenticated_at:string}
 */
final class ExamStationCredentialService
{
    /** @return array{raw_credential:string,credential_id:string,station_id:string,credential_sequence:int} */
    public function issue(
        string $organizationId,
        string $stationId,
        string $issuedByUserId,
        ?CarbonImmutable $issuedAt = null,
    ): array {
        $issuedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use ($organizationId, $stationId, $issuedByUserId, $issuedAt): array {
            $station = DB::table('exam_stations')
                ->where('organization_id', $organizationId)
                ->where('id', $stationId)
                ->lockForUpdate()
                ->first();
            if ($station === null) {
                throw ResourceDomainException::notFound('Exam station not found.');
            }
            if ((string) $station->administrative_status !== 'enabled') {
                throw ResourceDomainException::conflict('Disabled exam station cannot receive a credential.');
            }

            $current = DB::table('exam_station_credentials')
                ->where('organization_id', $organizationId)
                ->where('exam_station_id', $stationId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($current !== null) {
                throw ResourceDomainException::conflict('Exam station already has a current credential.');
            }

            $sequence = (int) DB::table('exam_station_credentials')
                ->where('organization_id', $organizationId)
                ->where('exam_station_id', $stationId)
                ->max('credential_sequence') + 1;

            return $this->insertCredential(
                $organizationId,
                $stationId,
                $issuedByUserId,
                $sequence,
                $issuedAt,
            );
        });
    }

    /** @return array{raw_credential:string,credential_id:string,station_id:string,credential_sequence:int} */
    public function rotate(
        string $organizationId,
        string $stationId,
        string $issuedByUserId,
        string $reasonCode = 'rotated',
        ?CarbonImmutable $issuedAt = null,
    ): array {
        $reasonCode = trim($reasonCode);
        if ($reasonCode === '') {
            throw new LogicException('Station credential rotation reason code is required.');
        }
        $issuedAt ??= CarbonImmutable::now();

        return DB::transaction(function () use (
            $organizationId,
            $stationId,
            $issuedByUserId,
            $reasonCode,
            $issuedAt,
        ): array {
            $station = DB::table('exam_stations')
                ->where('organization_id', $organizationId)
                ->where('id', $stationId)
                ->lockForUpdate()
                ->first();
            if ($station === null) {
                throw ResourceDomainException::notFound('Exam station not found.');
            }
            if ((string) $station->administrative_status !== 'enabled') {
                throw ResourceDomainException::conflict('Disabled exam station cannot rotate a credential.');
            }

            /** @var CredentialRow|null $current */
            $current = DB::table('exam_station_credentials')
                ->where('organization_id', $organizationId)
                ->where('exam_station_id', $stationId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                throw ResourceDomainException::conflict('Exam station has no current credential to rotate.');
            }

            DB::table('exam_station_credentials')->where('id', $current->id)->update([
                'revoked_at' => $issuedAt,
                'revoke_reason_code' => $reasonCode,
            ]);
            DB::table('exam_stations')->where('id', $stationId)->update([
                'last_authenticated_heartbeat_at' => null,
                'updated_at' => $issuedAt,
            ]);

            $sequence = (int) $current->credential_sequence + 1;

            return $this->insertCredential(
                $organizationId,
                $stationId,
                $issuedByUserId,
                $sequence,
                $issuedAt,
            );
        });
    }

    /** @return StationCredentialContext */
    public function resolveCurrent(
        string $rawCredential,
        ?string $expectedOrganizationId = null,
        ?string $expectedStationId = null,
        ?CarbonImmutable $effectiveAt = null,
    ): array {
        [$lookupId, $secret] = $this->parse($rawCredential);

        /** @var CredentialRow|null $credential */
        $credential = DB::table('exam_station_credentials')
            ->where('lookup_id', $lookupId)
            ->first();
        $credential = $this->requireCredential(
            $credential,
            $secret,
            $expectedOrganizationId,
            $expectedStationId,
        );

        $organizationId = (string) $credential->organization_id;
        $stationId = (string) $credential->exam_station_id;
        if (! DB::table('exam_station_credentials')
            ->where('organization_id', $organizationId)
            ->where('exam_station_id', $stationId)
            ->where('id', $credential->id)
            ->whereNull('revoked_at')
            ->exists()) {
            throw $this->invalid();
        }

        $station = DB::table('exam_stations')
            ->where('organization_id', $organizationId)
            ->where('id', $stationId)
            ->first();
        if ($station === null || (string) $station->administrative_status !== 'enabled') {
            throw $this->invalid();
        }

        $effectiveAt ??= CarbonImmutable::now();

        return [
            'credential_id' => (string) $credential->id,
            'organization_id' => $organizationId,
            'station_id' => $stationId,
            'credential_sequence' => (int) $credential->credential_sequence,
            'authenticated_at' => $effectiveAt->toIso8601String(),
        ];
    }

    /** @return StationCredentialContext */
    public function heartbeat(
        string $rawCredential,
        ?string $expectedOrganizationId = null,
        ?CarbonImmutable $effectiveAt = null,
    ): array {
        $effectiveAt ??= CarbonImmutable::now();
        $binding = $this->resolveCurrent($rawCredential, $expectedOrganizationId, null, $effectiveAt);

        return DB::transaction(fn (): array => $this->authenticateForStation(
            $rawCredential,
            $binding['organization_id'],
            $binding['station_id'],
            $effectiveAt,
        ));
    }

    /**
     * Authenticate the exact current credential while the caller is already in the
     * business transaction. This method locks the logical station before locking
     * the credential and refreshes the authenticated heartbeat.
     *
     * @return StationCredentialContext
     */
    public function authenticateForStation(
        string $rawCredential,
        string $expectedOrganizationId,
        string $expectedStationId,
        ?CarbonImmutable $effectiveAt = null,
    ): array {
        $this->assertTransaction();
        $effectiveAt ??= CarbonImmutable::now();
        [$lookupId, $secret] = $this->parse($rawCredential);

        $station = DB::table('exam_stations')
            ->where('organization_id', $expectedOrganizationId)
            ->where('id', $expectedStationId)
            ->lockForUpdate()
            ->first();
        if ($station === null || (string) $station->administrative_status !== 'enabled') {
            throw $this->invalid();
        }

        /** @var CredentialRow|null $credential */
        $credential = DB::table('exam_station_credentials')
            ->where('lookup_id', $lookupId)
            ->where('organization_id', $expectedOrganizationId)
            ->where('exam_station_id', $expectedStationId)
            ->lockForUpdate()
            ->first();
        $credential = $this->requireCredential(
            $credential,
            $secret,
            $expectedOrganizationId,
            $expectedStationId,
        );

        /** @var CredentialRow|null $current */
        $current = DB::table('exam_station_credentials')
            ->where('organization_id', $expectedOrganizationId)
            ->where('exam_station_id', $expectedStationId)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
        if ($current === null || (string) $current->id !== (string) $credential->id) {
            throw $this->invalid();
        }

        DB::table('exam_stations')->where('id', $expectedStationId)->update([
            'last_authenticated_heartbeat_at' => $effectiveAt,
            'updated_at' => $effectiveAt,
        ]);

        return [
            'credential_id' => (string) $credential->id,
            'organization_id' => $expectedOrganizationId,
            'station_id' => $expectedStationId,
            'credential_sequence' => (int) $credential->credential_sequence,
            'authenticated_at' => $effectiveAt->toIso8601String(),
        ];
    }

    /** @return array{raw_credential:string,credential_id:string,station_id:string,credential_sequence:int} */
    private function insertCredential(
        string $organizationId,
        string $stationId,
        string $issuedByUserId,
        int $sequence,
        CarbonImmutable $issuedAt,
    ): array {
        $lookupId = (string) Str::uuid();
        $secret = $this->base64Url(random_bytes(32));
        $rawCredential = $lookupId.'.'.$secret;
        $credentialId = (string) Str::uuid7();
        $keyVersion = 1;

        DB::table('exam_station_credentials')->insert([
            'id' => $credentialId,
            'organization_id' => $organizationId,
            'exam_station_id' => $stationId,
            'credential_sequence' => $sequence,
            'lookup_id' => $lookupId,
            'secret_verifier' => hash_hmac('sha256', $secret, $this->verifierKey($keyVersion)),
            'verifier_key_version' => $keyVersion,
            'issued_at' => $issuedAt,
            'revoked_at' => null,
            'revoke_reason_code' => null,
            'issued_by_user_id' => $issuedByUserId,
            'created_at' => $issuedAt,
        ]);

        return [
            'raw_credential' => $rawCredential,
            'credential_id' => $credentialId,
            'station_id' => $stationId,
            'credential_sequence' => $sequence,
        ];
    }

    /**
     * @param  CredentialRow|null  $credential
     * @return CredentialRow
     */
    private function requireCredential(
        ?object $credential,
        string $secret,
        ?string $expectedOrganizationId,
        ?string $expectedStationId,
    ): object {
        if ($credential === null || $credential->revoked_at !== null) {
            throw $this->invalid();
        }

        if (($expectedOrganizationId !== null && (string) $credential->organization_id !== $expectedOrganizationId)
            || ($expectedStationId !== null && (string) $credential->exam_station_id !== $expectedStationId)) {
            throw $this->invalid();
        }

        $computed = hash_hmac(
            'sha256',
            $secret,
            $this->verifierKey((int) $credential->verifier_key_version),
        );
        if (! hash_equals((string) $credential->secret_verifier, $computed)) {
            throw $this->invalid();
        }

        return $credential;
    }

    /** @return array{0:string,1:string} */
    private function parse(string $rawCredential): array
    {
        $parts = explode('.', trim($rawCredential), 2);
        if (count($parts) !== 2 || ! Str::isUuid($parts[0]) || strlen($parts[1]) < 43) {
            throw $this->invalid();
        }

        return [$parts[0], $parts[1]];
    }

    private function verifierKey(int $version): string
    {
        if ($version !== 1) {
            throw new LogicException('Unsupported internal exam station verifier key version.');
        }

        $key = config('internal_exams.station_verifier_key_v1');
        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('INTERNAL_EXAM_STATION_VERIFIER_KEY_V1 must contain at least 32 bytes.');
        }

        return $key;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Exam station authentication mutation must run inside the business transaction.');
        }
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function invalid(): ResourceDomainException
    {
        return new ResourceDomainException(
            'INVALID_EXAM_STATION_CREDENTIAL',
            401,
            'Invalid or revoked exam station credential.',
        );
    }
}
