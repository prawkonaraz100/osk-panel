<?php

namespace App\Support\Operations;

use App\Mail\ProductionPagingSmokeMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;

final class ProductionOperationsSmoke
{
    public const CONFIRMATION = 'SEND-INCIDENT-PAGING-SMOKE';

    public function __construct(
        private readonly CoreReconciliationScanner $reconciliation,
    ) {}

    /**
     * @return array{
     *   result:string,
     *   incident_policy_version:string,
     *   contact_refs_configured:bool,
     *   missing_contact_refs:list<string>,
     *   reconciliation_status:string,
     *   reconciliation_findings_total:int,
     *   reconciliation_mutations_performed:int,
     *   alert_attempted:bool,
     *   alert_sent:bool,
     *   smoke_id:?string,
     *   human_ack_proven:bool,
     *   scheduler_runtime_proven:bool,
     *   pkk_in_scope:bool
     * }
     */
    public function run(bool $sendAlert, ?string $confirmation = null): array
    {
        $policyVersion = config('incident_response.policy_version');
        if (! is_string($policyVersion) || trim($policyVersion) === '') {
            throw new LogicException('Incident-response policy version is unavailable.');
        }

        $missing = $this->missingContactRefs();
        if ($missing !== []) {
            return $this->result(
                'REFUSED_MISSING_CONTACT_REFS',
                $policyVersion,
                $missing,
                'NOT_RUN',
                0,
                false,
                false,
                null,
            );
        }

        $reconciliation = $this->reconciliation->scan();
        $reconciliationStatus = (string) $reconciliation['status'];
        $findingsTotal = (int) $reconciliation['findings_total'];
        $mutations = (int) $reconciliation['mutations_performed'];

        if ($mutations !== 0) {
            throw new LogicException('Production smoke reconciliation must remain read-only.');
        }

        if ($findingsTotal > 0) {
            return $this->result(
                'REFUSED_RECONCILIATION_FINDINGS',
                $policyVersion,
                [],
                $reconciliationStatus,
                $findingsTotal,
                false,
                false,
                null,
            );
        }

        if (! $sendAlert) {
            return $this->result(
                'PASS_VALIDATE_ONLY',
                $policyVersion,
                [],
                $reconciliationStatus,
                0,
                false,
                false,
                null,
            );
        }

        if ((bool) config('incident_response.paging_smoke.enabled', false) === false) {
            throw new LogicException('Production paging smoke delivery is disabled.');
        }

        if (! is_string($confirmation) || ! hash_equals(self::CONFIRMATION, $confirmation)) {
            throw new LogicException('Production paging smoke confirmation token mismatch.');
        }

        $recipient = config('incident_response.paging_smoke.recipient');
        if (! is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new LogicException('Production paging smoke recipient must be a valid deployment email address.');
        }

        $mailer = config('incident_response.paging_smoke.mailer');
        if (! is_string($mailer) || trim($mailer) === '') {
            throw new LogicException('Production paging smoke mailer is not configured.');
        }
        if (in_array($mailer, ['log', 'array'], true)) {
            throw new LogicException('Production paging smoke refuses non-delivery mail transports.');
        }

        $smokeId = (string) Str::uuid7();
        Mail::mailer($mailer)
            ->to($recipient)
            ->send(new ProductionPagingSmokeMail(
                $smokeId,
                CarbonImmutable::now()->toIso8601String(),
                $policyVersion,
            ));

        return $this->result(
            'PASS_ALERT_SENT_AWAITING_HUMAN_ACK',
            $policyVersion,
            [],
            $reconciliationStatus,
            0,
            true,
            true,
            $smokeId,
        );
    }

    /** @return list<string> */
    private function missingContactRefs(): array
    {
        $refs = config('incident_response.contact_refs');
        if (! is_array($refs)) {
            return ['contact_refs'];
        }

        $required = [
            'primary_on_call',
            'technical_lead',
            'privacy_lead',
            'business_liaison',
            'communications_lead',
            'paging_channel',
        ];

        $missing = [];
        foreach ($required as $key) {
            $value = $refs[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $missing
     * @return array{
     *   result:string,
     *   incident_policy_version:string,
     *   contact_refs_configured:bool,
     *   missing_contact_refs:list<string>,
     *   reconciliation_status:string,
     *   reconciliation_findings_total:int,
     *   reconciliation_mutations_performed:int,
     *   alert_attempted:bool,
     *   alert_sent:bool,
     *   smoke_id:?string,
     *   human_ack_proven:bool,
     *   scheduler_runtime_proven:bool,
     *   pkk_in_scope:bool
     * }
     */
    private function result(
        string $result,
        string $policyVersion,
        array $missing,
        string $reconciliationStatus,
        int $findingsTotal,
        bool $alertAttempted,
        bool $alertSent,
        ?string $smokeId,
    ): array {
        return [
            'result' => $result,
            'incident_policy_version' => $policyVersion,
            'contact_refs_configured' => $missing === [],
            'missing_contact_refs' => $missing,
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_findings_total' => $findingsTotal,
            'reconciliation_mutations_performed' => 0,
            'alert_attempted' => $alertAttempted,
            'alert_sent' => $alertSent,
            'smoke_id' => $smokeId,
            'human_ack_proven' => false,
            'scheduler_runtime_proven' => false,
            'pkk_in_scope' => false,
        ];
    }
}
