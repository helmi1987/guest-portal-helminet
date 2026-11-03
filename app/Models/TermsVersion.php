<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Terms of use, versioned per locale (CONCEPT.md §7.1, §9). Versions are never edited, only added.
 * Without any published version guests see the default text of the lang files (unsaved instance).
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
        // Numbers may repeat after a federation merge (no unique index): newest publication wins.
        return static::query()->where('locale', $locale)->orderByDesc('number')->orderByDesc('published_at')
            ->orderByDesc('id')->first();
    }

    /**
     * Current terms shown to a guest: own locale, else the app default locale, else any locale; when
     * nothing at all is published, the default text (unsaved, id and number null).
     */
    public static function current(string $locale): self
    {
        foreach (array_unique([$locale, config('app.locale'), config('app.fallback_locale')]) as $candidate) {
            if ($terms = static::latestFor((string) $candidate)) {
                return $terms;
            }
        }

        return static::query()->latest('published_at')->first() ?? static::defaultFor($locale);
    }

    /** Unsaved instance with the default text of the locale (app default locale when it has none). */
    public static function defaultFor(string $locale): self
    {
        $locale = Lang::hasForLocale('portal.terms.default', $locale) ? $locale : (string) config('app.locale');

        return new static(['locale' => $locale, 'number' => null, 'body' => static::defaultText($locale), 'published_at' => null]);
    }

    /** Default text of a locale from the lang files (paragraphs separated by blank lines). */
    public static function defaultText(string $locale): string
    {
        return trim(str_replace("\r\n", "\n", (string) trans('portal.terms.default', [], $locale)));
    }

    /** The default text shown while nothing is published (acceptances then store version null). */
    public function isDefault(): bool
    {
        return ! $this->exists && $this->number === null;
    }

    /**
     * Publish the default text as version 1 for every locale without any version (locales with versions
     * stay untouched); audited per locale. Used by the admin button and when a new portal gets its first
     * site.
     *
     * @return list<string> locales published
     */
    public static function publishDefaults(): array
    {
        return DB::transaction(function () {
            $published = [];
            foreach (config('portal.locales') as $locale) {
                if (static::query()->where('locale', $locale)->exists()) {
                    continue;
                }
                $terms = static::publish($locale, static::defaultText($locale));
                Audit::log('terms.published', $terms, ['locale' => $locale, 'number' => $terms->number, 'default' => true]);
                $published[] = $locale;
            }

            return $published;
        });
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
