<?php

namespace App\Support;

use App\Models\Instance;
use App\Models\LocalSetting;
use Illuminate\Support\Str;

/**
 * Identity of this instance. Generated once and stored in local (non-replicated) settings.
 */
class LocalInstance
{
    private ?string $id = null;

    public function id(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        $id = LocalSetting::get('instance_id');

        if ($id === null) {
            $id = (string) Str::ulid();
            LocalSetting::put('instance_id', $id);

            $instance = new Instance(['name' => config('portal.instance_name')]);
            $instance->forceFill(['id' => $id, 'origin_instance_id' => $id])->save();
        }

        return $this->id = $id;
    }
}
