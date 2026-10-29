<?php

namespace App\Models\Concerns;

/**
 * Site restriction through replicated link rows (no rows = all sites), used by grants and MAC
 * whitelist entries. The model defines siteLinks() (HasMany with a site_id column).
 */
trait HasSiteLinks
{
    /** @return list<string> site ids; empty = all sites */
    public function siteIds(): array
    {
        return $this->siteLinks->pluck('site_id')->all();
    }

    public function allowsSite(?string $siteId): bool
    {
        $ids = $this->siteIds();

        return $ids === [] || in_array($siteId, $ids, true);
    }

    /** Replace the site restriction; removed links become tombstones. */
    public function syncSites(array $siteIds): void
    {
        $siteIds = array_values(array_unique($siteIds));
        foreach ($this->siteLinks as $link) {
            if (! in_array($link->site_id, $siteIds, true)) {
                $link->delete();
            }
        }
        foreach (array_diff($siteIds, $this->siteLinks->pluck('site_id')->all()) as $siteId) {
            $this->siteLinks()->create(['site_id' => $siteId]);
        }
        $this->unsetRelation('siteLinks');
    }
}
