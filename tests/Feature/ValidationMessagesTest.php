<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\MessageBag;
use Tests\TestCase;

/**
 * Guest form errors are shown in the guest's language, with labels instead of field names.
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const FORMS = [
        '/portal/code' => ['first_name', 'last_name', 'terms', 'code'],
        '/portal/approval' => ['first_name', 'last_name', 'terms'],
        '/portal/sms' => ['first_name', 'last_name', 'terms', 'phone'],
    ];

    /** Raw field names and English fragments that must not appear in a translated message. */
    private const FORBIDDEN = ['first_name', 'last_name', 'first name', 'last name', 'terms', 'phone',
        'The ', ' field', 'must', 'required', 'accepted', 'Please'];

    public function test_empty_guest_forms_show_translated_messages_in_de_it_fr(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['de', 'it', 'fr'] as $locale) {
            foreach (self::FORMS as $uri => $fields) {
                $response = $this->onZone('guest')->withSession(['locale' => $locale])->post($uri, []);
                $response->assertSessionHasErrors($fields);

                /** @var MessageBag $errors */
                $errors = session('errors')->getBag('default');
                foreach ($errors->all() as $message) {
                    foreach (self::FORBIDDEN as $fragment) {
                        $this->assertStringNotContainsStringIgnoringCase($fragment, $message, "[$locale $uri] $message");
                    }
                }
                $this->assertSame(__('validation.custom.terms.accepted', [], $locale), $errors->first('terms'));
            }
        }
    }

    public function test_english_messages_use_labels(): void
    {
        $this->onZone('guest')->withSession(['locale' => 'en'])->post('/portal/code', [])
            ->assertSessionHasErrors(['first_name' => 'The first name field is required.',
                'terms' => 'Please accept the terms of use.']);
    }
}
