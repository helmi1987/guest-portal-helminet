<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Terms of use, versioned per locale (CONCEPT.md §7.1, §9). Versions are never edited, only added.
 */
#[Fillable(['locale', 'number', 'body', 'published_at'])]
class TermsVersion extends Model
{
    use Replicated;

    protected function casts(): array
    {
        return ['number' => 'integer', 'published_at' => 'datetime'];
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class);
    }

    public static function latestFor(string $locale): ?self
    {
        return static::query()->where('locale', $locale)->orderByDesc('number')->first();
    }

    /** Current terms shown to a guest: own locale, else the app default locale, else any locale. */
    public static function current(string $locale): ?self
    {
        foreach (array_unique([$locale, config('app.locale'), config('app.fallback_locale')]) as $candidate) {
            if ($terms = static::latestFor((string) $candidate)) {
                return $terms;
            }
        }

        return static::query()->latest('published_at')->first();
    }

    /** Store a new version when the text differs from the latest one; returns the latest version. */
    public static function publish(string $locale, string $body): self
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        return DB::transaction(function () use ($locale, $body) {
            $latest = static::latestFor($locale);
            if ($latest !== null && $latest->body === $body) {
                return $latest;
            }

            return static::create([
                'locale' => $locale,
                'number' => ($latest?->number ?? 0) + 1,
                'body' => $body,
                'published_at' => now(),
            ]);
        });
    }

    /** @return list<string> paragraphs (plain text, rendered escaped) */
    public function paragraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $this->body))));
    }
}
