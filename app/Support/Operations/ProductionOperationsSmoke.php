<?php

namespace App\Support\Operations;

use LogicException;

final class ProductionOperationsSmoke
{
    public const CONFIRMATION = 'SEND-PRODUCTION-OPS-SMOKE';

    public function __construct(
        private readonly CoreReconciliationScanner $reconciliation,
        private readonly OperationalAlertDispatcher $alerts,
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
     *   alert_delivery_status:string,
     *   alert_event_id:?string,
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
                'not_requested',
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
                'not_requested',
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
                'not_requested',
                null,
            );
        }

        if (! is_string($confirmation) || ! hash_equals(self::CONFIRMATION, $confirmation)) {
            throw new LogicException('Production operations smoke confirmation token mismatch.');
        }

        $alert = $this->alerts->dispatch('synthetic_smoke');
        $deliveryStatus = (string) $alert['status'];
        $eventId = (string) $alert['event_id'];

        if ($deliveryStatus !== 'delivered') {
            return $this->result(
                'FAIL_ALERT_DELIVERY',
                $policyVersion,
                [],
                $reconciliationStatus,
                0,
                true,
                $deliveryStatus,
                $eventId,
            );
        }

        return $this->result(
            'PASS_ALERT_DELIVERED_AWAITING_HUMAN_ACK',
            $policyVersion,
            [],
            $reconciliationStatus,
            0,
            true,
            $deliveryStatus,
            $eventId,
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
     * @param  list<string>  $missing
     * @return array{
     *   result:string,
     *   incident_policy_version:string,
     *   contact_refs_configured:bool,
     *   missing_contact_refs:list<string>,
     *   reconciliation_status:string,
     *   reconciliation_findings_total:int,
     *   reconciliation_mutations_performed:int,
     *   alert_attempted:bool,
     *   alert_delivery_status:string,
     *   alert_event_id:?string,
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
        string $alertDeliveryStatus,
        ?string $alertEventId,
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
            'alert_delivery_status' => $alertDeliveryStatus,
            'alert_event_id' => $alertEventId,
            'human_ack_proven' => false,
            'scheduler_runtime_proven' => false,
            'pkk_in_scope' => false,
        ];
    }
}
