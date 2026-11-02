<?php

namespace App\Nas\RouterOs;

use App\Models\NasClient;

/**
 * NAS driver with a provisioning rule editor for CAPsMAN (setup assistant, CONCEPT.md §9, decision
 * 2026-11-02): rules and configurations of one router from its stored state, and a guarded script from
 * the admin's edits. Nothing is stored; the script is generated server-side from the stored state.
 */
interface ProvisioningEditor
{
    /**
     * Provisioning rules per CAPsMAN package of one router from its stored state: [mode (capsman1|capsman2)
     * => ['path', 'band_prop', 'bands' => band choices, 'configurations' => names, 'rules' => list of
     * ['id', 'band', 'master', 'slaves', 'radio_mac', 'identity', 'disabled', 'comment', 'own']]]. Packages
     * without rules and configurations in the state are left out.
     *
     * @return array<string, array<string, mixed>>
     */
    public function provisioningRules(NasClient $nas): array;

    /**
     * Script for the edits of one package: changed rules (by .id, guarded by the values of the state) and
     * new rules (added only when no rule with the same band matcher and master exists). Throws a
     * validation exception for invalid input (unknown rule or configuration, invalid names).
     *
     * @param  array<string, mixed>  $input  ['mode', 'rules' => [id => ['master', 'slaves']], 'new' => list of ['band', 'master', 'slaves']]
     */
    public function provisioningScript(NasClient $nas, array $input): string;

    /**
     * CAP radios per CAPsMAN mode from the stored router states (guest SSID per CAP): mode => list of
     * ['mac', 'label', 'configuration'].
     *
     * @param  iterable<NasClient>  $routers
     * @return array<string, list<array{mac: string, label: string, configuration: string}>>
     */
    public function wifiCapOptions(iterable $routers): array;
}
