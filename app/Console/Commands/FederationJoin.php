<?php

namespace App\Console\Commands;

use App\Federation\Pairing;
use App\Federation\PairingException;
use App\Federation\PeerCertificate;
use App\Models\Instance;
use App\Support\LocalInstance;
use Illuminate\Console\Command;

/**
 * Join a federation (CONCEPT.md §10.2). Only a fresh instance (before the first admin is created)
 * can join: the federation's users, sites and settings replace the setup. The token and the member's
 * certificate pin are shown on the member's federation page.
 */
class FederationJoin extends Command
{
    protected $signature = 'portal:federation:join
    {url : Federation URL of a member, normally the hub (e.g. https://10.0.0.1:9443)}
    {token : One-time join token from the member\'s federation page}
    {fingerprint : Certificate pin of the member (sha256//...), shown with the token}
    {--name= : Name of this instance}
    {--federation-url= : URL under which the other instances reach this one}
    {--admin-url= : Admin URL of this instance (approval links)}';

    protected $description = 'Join this fresh instance to a federation';

    public function handle(Pairing $pairing, LocalInstance $instance): int
    {
        if (! PeerCertificate::isPin($this->argument('fingerprint'))) {
            $this->error('The fingerprint must look like sha256//<base64> (copy it from the member\'s federation page).');

            return self::FAILURE;
        }
        if (($reason = $pairing->isFresh()) !== null) {
            $this->error("This instance cannot join: $reason. Only a new instance without users or data can join.");

            return self::FAILURE;
        }
        $self = Instance::query()->findOrFail($instance->id());
        $self->fill(array_filter([
            'name' => $this->option('name'),
            'federation_url' => $this->option('federation-url') ? rtrim($this->option('federation-url'), '/') : null,
            'admin_url' => $this->option('admin-url') ? rtrim($this->option('admin-url'), '/') : null,
        ]))->save();

        try {
            $result = $pairing->join((string) $this->argument('url'), (string) $this->argument('token'), (string) $this->argument('fingerprint'));
        } catch (PairingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Joined the federation of instance {$result['instance_id']} ({$result['rows']} rows received).");
        $this->line('Log in with an existing admin account of the federation. Replication runs every 30 seconds.');

        return self::SUCCESS;
    }
}
