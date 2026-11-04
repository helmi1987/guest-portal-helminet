<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Sms\Drivers\HttpSmsDriver;
use App\Sms\Drivers\SmsDrivers;
use App\Sms\SmsFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SMS provider drivers (CONCEPT.md §7.3): request format against the provider docs (Http::fake), parsed
 * message id, and failure mapping (status or provider code only; no secret, no number; failover or not).
 */
class SmsProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+41790000000';

    private const TEXT = 'Your code: 123456';

    /**
     * driver => [config, success response [body, status], request check, expected id,
     *            failure response [body, status], expected error message, recipient (no failover)]
     */
    public static function providers(): array
    {
        $basic = fn (Request $r, string $user, string $pass) => $r->hasHeader('Authorization', 'Basic '.base64_encode("$user:$pass"));

        return [
            'twilio' => ['twilio', ['account_sid' => 'AC123', 'auth_token' => 'tw-secret-token', 'from' => '+41780000000'],
                [['sid' => 'SM1'], 201],
                fn (Request $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
                    && $basic($r, 'AC123', 'tw-secret-token') && $r->isForm()
                    && $r['To'] === self::PHONE && $r['From'] === '+41780000000' && $r['Body'] === self::TEXT,
                'SM1', [['code' => 21211, 'message' => 'The To number +41790000000 is not valid', 'status' => 400], 400],
                'Twilio send failed: HTTP 400 (code 21211)', true],
            'seven' => ['seven', ['api_key' => 'seven-secret-key', 'from' => 'Portal'],
                [['success' => '100', 'messages' => [['id' => '77', 'success' => true]]], 200],
                fn (Request $r) => $r->url() === 'https://gateway.seven.io/api/sms' && $r->hasHeader('X-Api-Key', 'seven-secret-key')
                    && $r['to'] === '41790000000' && $r['text'] === self::TEXT && $r['from'] === 'Portal',
                '77', [['success' => '900'], 200], 'seven.io rejected the message: code 900', false],
            'clicksend' => ['clicksend', ['username' => 'cs-user', 'api_key' => 'cs-secret-key'],
                [['http_code' => 200, 'response_code' => 'SUCCESS', 'data' => ['messages' => [['status' => 'SUCCESS', 'message_id' => 'CS-1']]]], 200],
                fn (Request $r) => $r->url() === 'https://rest.clicksend.com/v3/sms/send' && $basic($r, 'cs-user', 'cs-secret-key')
                    && $r['messages'][0]['to'] === self::PHONE && $r['messages'][0]['body'] === self::TEXT && ! isset($r['messages'][0]['from']),
                'CS-1', [['response_code' => 'SUCCESS', 'data' => ['messages' => [['status' => 'INVALID_RECIPIENT', 'to' => self::PHONE]]]], 200],
                'ClickSend rejected the message: code INVALID_RECIPIENT', true],
            'smsapi' => ['smsapi', ['token' => 'smsapi-secret-token', 'from' => 'Portal'],
                [['count' => 1, 'list' => [['id' => 'SA1', 'number' => '41790000000']]], 200],
                fn (Request $r) => $r->url() === 'https://api.smsapi.com/sms.do' && $r->hasHeader('Authorization', 'Bearer smsapi-secret-token')
                    && $r['to'] === '41790000000' && $r['message'] === self::TEXT && $r['format'] === 'json' && $r['from'] === 'Portal',
                'SA1', [['error' => 101, 'message' => 'Authorization failed'], 200], 'SMSAPI rejected the message: code 101', false],
            '46elks' => ['46elks', ['username' => 'u123', 'password' => 'elks-secret-pass', 'sender' => 'Portal'],
                [['id' => 's70df', 'status' => 'created'], 200],
                fn (Request $r) => $r->url() === 'https://api.46elks.com/a1/sms' && $basic($r, 'u123', 'elks-secret-pass') && $r->isForm()
                    && $r['to'] === self::PHONE && $r['from'] === 'Portal' && $r['message'] === self::TEXT,
                's70df', ['Service unavailable', 503], '46elks send failed: HTTP 503', false],
            'cellsynt' => ['cellsynt', ['username' => 'cs', 'password' => 'cell-secret-pass', 'sender' => 'Portal'],
                ['OK: abc123', 200],
                fn (Request $r) => $r->url() === 'https://se-1.cellsynt.net/sms.php' && $r['username'] === 'cs' && $r['password'] === 'cell-secret-pass'
                    && $r['destination'] === '0041790000000' && $r['originatortype'] === 'alpha' && $r['originator'] === 'Portal'
                    && $r['type'] === 'text' && $r['text'] === self::TEXT,
                'abc123', ['Error: Invalid username or password cell-secret-pass', 200], 'Cellsynt rejected the message: code error', false],
            'octopush' => ['octopush', ['login' => 'admin@example.com', 'api_key' => 'octo-secret-key', 'sms_type' => 'sms_low_cost'],
                [['sms_ticket' => 'sms_5f', 'number_of_contacts' => 1], 201],
                fn (Request $r) => $r->url() === 'https://api.octopush.com/v1/public/sms-campaign/send'
                    && $r->hasHeader('api-key', 'octo-secret-key') && $r->hasHeader('api-login', 'admin@example.com')
                    && $r['recipients'] === [['phone_number' => self::PHONE]] && $r['type'] === 'sms_low_cost' && $r['purpose'] === 'alert',
                'sms_5f', [['code' => 101, 'message' => 'Bad credentials'], 401], 'Octopush send failed: HTTP 401 (code 101)', false],
            'smseagle' => ['smseagle', ['url' => 'https://smseagle.example.com/', 'access_token' => 'eagle-secret-token', 'device_id' => '2'],
                [[['message_id' => 714, 'status' => 'queued', 'number' => self::PHONE]], 200],
                fn (Request $r) => $r->url() === 'https://smseagle.example.com/api/v2/messages/sms' && $r->hasHeader('access-token', 'eagle-secret-token')
                    && $r['to'] === [self::PHONE] && $r['encoding'] === 'standard' && $r['modem_no'] === 2,
                '714', [['error' => 'Unauthorized'], 401], 'SMSEagle send failed: HTTP 401', false],
            'smsplanet' => ['smsplanet', ['token' => 'planet-secret-token', 'sender' => 'TEST'],
                [['messageId' => '191919'], 200],
                fn (Request $r) => $r->url() === 'https://api2.smsplanet.pl/sms' && $r->hasHeader('Authorization', 'Bearer planet-secret-token')
                    && $r->isForm() && $r['from'] === 'TEST' && $r['to'] === self::PHONE && $r['msg'] === self::TEXT,
                '191919', [['errorMsg' => 'Invalid key', 'errorCode' => 101], 200], 'SMS Planet rejected the message: code 101', false],
            'promosms' => ['promosms', ['login' => 'promo', 'password' => 'promo-secret-pass', 'from' => 'Portal'],
                [['response' => ['status' => 0, 'contentId' => 'P-1', 'sentSmsCount' => 1]], 200],
                fn (Request $r) => $r->url() === 'https://promosms.com/api/rest/v3_2/sms' && $basic($r, 'promo', 'promo-secret-pass')
                    && $r['recipients'] === ['41790000000'] && $r['type'] === 3 && $r['sender'] === 'Portal' && $r['special-chars'] === false,
                'P-1', [['response' => ['status' => 400]], 200], 'PromoSMS rejected the message: code 400', false],
            'serwersms' => ['serwersms', ['username' => 'serwer', 'password' => 'serwer-secret-pass'],
                [['success' => true, 'queued' => 1, 'items' => [['id' => '1c142d81c7', 'status' => 'queued']]], 200],
                fn (Request $r) => $r->url() === 'https://api2.serwersms.pl/messages/send_sms' && $r['username'] === 'serwer'
                    && $r['password'] === 'serwer-secret-pass' && $r['phone'] === self::PHONE && $r['details'] === true && ! isset($r['sender']),
                '1c142d81c7', [['success' => true, 'queued' => 0, 'unsent' => 1, 'items' => [['phone' => self::PHONE, 'status' => 'unsent', 'error_code' => 3103]]], 200],
                'SerwerSMS rejected the message: code 3103', true],
            'smsc' => ['smsc', ['login' => 'smscl', 'password' => 'smsc-secret-pass'],
                [['id' => 42, 'cnt' => 1], 200],
                fn (Request $r) => $r->url() === 'https://smsc.kz/sys/send.php' && $r->isForm() && $r['login'] === 'smscl'
                    && $r['psw'] === 'smsc-secret-pass' && $r['phones'] === '41790000000' && $r['fmt'] == 3 && $r['charset'] === 'utf-8',
                '42', [['error' => 'invalid number 41790000000', 'error_code' => 7], 200], 'SMSC rejected the message: code 7', true],
            'freemobile' => ['freemobile', ['login' => '12345678', 'password' => 'free-secret-key'],
                ['', 200],
                fn (Request $r) => $r->url() === 'https://smsapi.free-mobile.fr/sendmsg' && $r['user'] === '12345678'
                    && $r['pass'] === 'free-secret-key' && $r['msg'] === self::TEXT,
                null, ['', 403], 'Free Mobile send failed: HTTP 403 (code 403)', false],
            'smspartner' => ['smspartner', ['api_key' => 'partner-secret-key', 'from' => 'Portal'],
                [['success' => true, 'code' => 200, 'message_id' => 307], 200],
                fn (Request $r) => $r->url() === 'https://api.smspartner.fr/v1/send' && $r['apiKey'] === 'partner-secret-key'
                    && $r['phoneNumbers'] === self::PHONE && $r['message'] === self::TEXT && $r['sender'] === 'Portal',
                '307', [['success' => false, 'code' => 10, 'errors' => [['message' => 'bad key']]], 200], 'SMS Partner rejected the message: code 10', false],
            'smsmanager' => ['smsmanager', ['api_key' => 'manager-secret-key', 'gateway' => 'economy'],
                ['OK|932125742|41790000000', 200],
                fn (Request $r) => $r->url() === 'https://http-api-lts.smsmanager.cz' && $r->isForm() && $r['apikey'] === 'manager-secret-key'
                    && $r['number'] === self::PHONE && $r['message'] === self::TEXT && $r['gateway'] === 'economy',
                '932125742', ['ERROR|102', 200], 'SmsManager rejected the message: code 102', false],
            'gtx' => ['gtx', ['api_key' => 'gtx-secret-key', 'sender' => 'Portal'],
                [['message-count' => '1', 'message-status' => 'OK', 'message-id' => 'gtx-1'], 200],
                fn (Request $r) => $r->url() === 'https://rest.gtx-messaging.net/smsc/sendsms/gtx-secret-key/json' && $r->isForm()
                    && $r['from'] === 'Portal' && $r['to'] === self::PHONE && $r['text'] === self::TEXT && ! isset($r['coding']),
                'gtx-1', ['Internal error', 500], 'GTX Messaging send failed: HTTP 500', false],
            'aspsms' => ['aspsms', ['userkey' => 'ASPKEY', 'password' => 'asp-secret-pass', 'sender' => 'Portal'],
                [['StatusCode' => '1', 'StatusInfo' => 'OK'], 200],
                fn (Request $r) => $r->url() === 'https://json.aspsms.com/SendSimpleTextSMS' && $r['UserName'] === 'ASPKEY'
                    && $r['Password'] === 'asp-secret-pass' && $r['Originator'] === 'Portal' && str_starts_with($r['Recipients'][0], self::PHONE.':')
                    && $r['MessageText'] === self::TEXT && $r['ForceGSM7bit'] === false,
                true, [['StatusCode' => '5', 'StatusInfo' => 'Not enough credits'], 200], 'ASPSMS rejected the message: code 5', false],
            'ecall' => ['ecall', ['username' => 'ecuser', 'password' => 'ecall-secret-pass', 'sender' => 'Portal'],
                [['messageId' => 'ec-1', 'timestamp' => '2026-11-04T10:00:00Z'], 200],
                fn (Request $r) => $r->url() === 'https://rest.ecall.ch/api/v2/message' && $basic($r, 'ecuser', 'ecall-secret-pass')
                    && $r['channel'] === 'Sms' && $r['from'] === 'Portal' && $r['to'] === self::PHONE
                    && $r['content'] === ['type' => 'Text', 'text' => self::TEXT],
                'ec-1', [['errorCode' => 'TooManyMessagesSameReceiver', 'errorMessage' => 'too many to +41790000000'], 400],
                'eCall send failed: HTTP 400 (code TooManyMessagesSameReceiver)', true],
        ];
    }

    #[DataProvider('providers')]
    public function test_driver_sends_and_maps_failures(string $driver, array $config, array $ok, \Closure $check, string|bool|null $id,
        array $fail, string $error, bool $recipient): void
    {
        $channel = Channel::create(['kind' => 'sms', 'driver' => $driver, 'name' => $driver, 'enabled' => true, 'config' => $config]);
        foreach (array_keys(SmsDrivers::DRIVERS[$driver]::fields()) as $field) {
            $this->assertTrue(\Lang::has('portal.channels.fields.'.$field, 'de'), "label $field");
        }

        Http::fake(['*' => Http::sequence()->push($ok[0], $ok[1])->push($fail[0], $fail[1])]);
        $sent = SmsDrivers::make($driver)->send($channel, self::PHONE, self::TEXT);
        Http::assertSentCount(1);
        Http::assertSent($check);
        $id === true ? $this->assertMatchesRegularExpression('/^\d{9}$/', $sent) : $this->assertSame($id, $sent);

        try {
            SmsDrivers::make($driver)->send($channel, self::PHONE, self::TEXT);
            $this->fail('No exception');
        } catch (SmsFailure $e) {
            $this->assertSame($error, $e->getMessage());
            $this->assertSame($recipient, $e->recipient);
            $this->assertStringNotContainsString('41790000000', $e->getMessage());
            foreach ($channel->secretValues() as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function test_connection_errors_are_provider_failures_with_secrets_masked(): void
    {
        $channel = Channel::create(['kind' => 'sms', 'driver' => 'gtx', 'name' => 'GTX', 'enabled' => true,
            'config' => ['api_key' => 'gtx-secret-key', 'sender' => 'Portal']]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('cURL error 28 for https://rest.gtx-messaging.net/smsc/sendsms/gtx-secret-key/json to +41790000000');
            }

            return Http::response('', 302, ['Location' => 'https://elsewhere.example.com']);
        });

        try {
            SmsDrivers::make('gtx')->send($channel, self::PHONE, self::TEXT);
            $this->fail('No exception');
        } catch (SmsFailure $e) {
            $this->assertFalse($e->recipient);
            $this->assertStringNotContainsString('gtx-secret-key', $e->getMessage());
            $this->assertStringNotContainsString('41790000000', $e->getMessage());
        }

        // A redirect is never followed and counts as provider failure.
        try {
            SmsDrivers::make('gtx')->send($channel, self::PHONE, self::TEXT);
            $this->fail('No exception');
        } catch (SmsFailure $e) {
            $this->assertSame(['GTX Messaging send failed: HTTP 302', false], [$e->getMessage(), $e->recipient]);
        }
    }

    public function test_unicode_text_sets_the_provider_flag(): void
    {
        $this->assertTrue(HttpSmsDriver::isGsm7("Dein Code: 123456\nGäste-WLAN, gültig 10 Min. €"));
        $this->assertFalse(HttpSmsDriver::isGsm7('Il tuo codice: 123456 – Wi‑Fi'));
        $this->assertFalse(HttpSmsDriver::isGsm7('Код 123456'));

        $channel = Channel::create(['kind' => 'sms', 'driver' => 'gtx', 'name' => 'GTX', 'enabled' => true,
            'config' => ['api_key' => 'gtx-secret-key', 'sender' => 'Portal']]);
        Http::fake(['*' => Http::response(['message-id' => ['a', 'b']])]);
        $this->assertSame('a', SmsDrivers::make('gtx')->send($channel, self::PHONE, 'Код 123456'));
        Http::assertSent(fn (Request $r) => $r['coding'] == 2 && $r['charset'] === 'utf-8' && $r['text'] === 'Код 123456');
    }

    public function test_every_driver_has_labels_and_help_in_all_languages(): void
    {
        foreach (config('portal.locales') as $locale) {
            foreach (SmsDrivers::DRIVERS as $driver => $class) {
                $this->assertTrue(\Lang::has('portal.channels.drivers.'.$driver, $locale), "$locale $driver");
                $this->assertTrue(\Lang::has('portal.channels.driver_help.'.$driver, $locale), "$locale help $driver");
                foreach ($class::fields() as $field => $spec) {
                    $this->assertTrue(\Lang::has('portal.channels.fields.'.$field, $locale), "$locale $field");
                    foreach ($spec['options'] ?? [] as $option) {
                        $this->assertTrue(\Lang::has("portal.channels.options.$field.$option", $locale), "$locale $field.$option");
                    }
                }
            }
        }
    }

    public function test_channel_form_shows_driver_help_and_option_selects(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/channels/create?driver=octopush')->assertOk()
            ->assertSee(__('portal.channels.driver_help.octopush'))
            ->assertSee('<select name="config[sms_type]"', false)->assertSee(__('portal.channels.options.sms_type.sms_low_cost'));
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.store'), ['driver' => 'octopush', 'name' => 'Octo',
            'enabled' => '1', 'config' => ['login' => 'a@example.com', 'api_key' => 'k-1234', 'sms_type' => 'bogus']])
            ->assertSessionHasErrors('config.sms_type');
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('<option value="twilio">Twilio</option>', false)->assertSee(__('portal.channels.sms_add'));
    }
}
