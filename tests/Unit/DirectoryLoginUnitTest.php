<?php

namespace Tests\Unit;

use App\Auth\Directory\DirectoryConfig;
use App\Auth\Directory\DirectoryLogin;
use App\Auth\Directory\DirectoryUser;
use App\Http\Controllers\Admin\SiteDirectoryController;
use PHPUnit\Framework\TestCase;

/**
 * Directory account login helpers (CONCEPT.md §7.2 E): filter escaping, name splitting, group DN
 * comparison and the admin input checks.
 */
class DirectoryLoginUnitTest extends TestCase
{
    private static function config(array $overrides = []): DirectoryConfig
    {
        $v = $overrides + ['host' => 'dc1.example.com', 'port' => 636, 'tls' => 'ldaps', 'filter' => '(&(objectClass=user)(sAMAccountName={username}))'];

        return new DirectoryConfig($v['host'], $v['port'], $v['tls'], true, '', 'DC=example,DC=com', 'CN=svc,DC=example,DC=com',
            'svc-secret', $v['filter'], '', ['displayName'], 'mail', 5);
    }

    public function test_user_name_is_escaped_in_the_filter(): void
    {
        $config = self::config();
        $this->assertSame('(&(objectClass=user)(sAMAccountName=anna))', $config->filterFor('anna'));
        $this->assertSame('(&(objectClass=user)(sAMAccountName=\2a))', $config->filterFor('*'));
        $this->assertSame('(&(objectClass=user)(sAMAccountName=a\29\28|\28uid=\2a\29))', $config->filterFor('a)(|(uid=*)'));
        $this->assertSame('(&(objectClass=user)(sAMAccountName=\5c\00x))', $config->filterFor("\\\0x"));
        // Every {username} is replaced.
        $this->assertSame('(|(uid=b\2a)(mail=b\2a))', self::config(['filter' => '(|(uid={username})(mail={username}))'])->filterFor('b*'));
    }

    public function test_uri_per_tls_mode_and_ipv6(): void
    {
        $this->assertSame('ldaps://dc1.example.com:636', self::config()->uri());
        $this->assertSame('ldap://dc1.example.com:389', self::config(['tls' => 'starttls', 'port' => 389])->uri());
        $this->assertSame('ldap://10.0.0.5:389', self::config(['tls' => 'none', 'host' => '10.0.0.5', 'port' => 389])->uri());
        $this->assertSame('ldaps://[fd00::5]:636', self::config(['host' => 'fd00::5'])->uri());
    }

    public function test_config_dump_hides_the_password(): void
    {
        $this->assertStringNotContainsString('svc-secret', print_r(self::config(), true));
        $this->assertStringNotContainsString('svc-secret', var_export(self::config()->__debugInfo(), true));
    }

    public function test_names_are_split(): void
    {
        $this->assertSame(['Anna Maria', 'Muster'], DirectoryUser::splitName('Anna Maria Muster'));
        $this->assertSame(['Anna', 'Muster'], DirectoryUser::splitName('  Muster,  Anna '));
        $this->assertSame(['Anna', ''], DirectoryUser::splitName('Anna'));
        $this->assertSame(['Anna', 'von Muster'], DirectoryUser::splitName('von Muster, Anna'));
        $this->assertSame(['Anna', ''], DirectoryUser::splitName('Anna,'));
        $this->assertSame(['', ''], DirectoryUser::splitName(''));
    }

    public function test_names_and_email_from_attributes_with_fallbacks(): void
    {
        $user = DirectoryUser::fromAttributes('anna', ['displayname' => ['Anna Muster'], 'mail' => ['anna@example.com']], ['displayName'], 'mail');
        $this->assertSame(['Anna', 'Muster', 'anna@example.com'], [$user->firstName, $user->lastName, $user->email]);
        // No display name: givenName and sn
        $user = DirectoryUser::fromAttributes('anna', ['givenname' => ['Anna'], 'sn' => ['Muster'], 'mail' => ['not-an-email']], ['displayName'], 'mail');
        $this->assertSame(['Anna', 'Muster', null], [$user->firstName, $user->lastName, $user->email]);
        // One-word display name completed by sn; second name attribute used when the first is empty
        $user = DirectoryUser::fromAttributes('anna', ['displayname' => ['Anna'], 'sn' => ['Muster']], ['displayName'], '');
        $this->assertSame(['Anna', 'Muster', null], [$user->firstName, $user->lastName, $user->email]);
        $user = DirectoryUser::fromAttributes('anna', ['displayname' => [''], 'cn' => ['Ben Beispiel']], ['displayName', 'cn'], 'mail');
        $this->assertSame(['Ben', 'Beispiel'], [$user->firstName, $user->lastName]);
        // Nothing: the user name as first name
        $user = DirectoryUser::fromAttributes('jdoe', [], ['displayName'], 'mail');
        $this->assertSame(['jdoe', ''], [$user->firstName, $user->lastName]);
    }

    public function test_group_dns_compare_case_and_space_insensitive(): void
    {
        $attributes = ['memberof' => ['CN=Staff,OU=Groups,DC=example,DC=com', 'CN=Guest-WiFi,OU=Groups,DC=example,DC=com']];
        $this->assertTrue(DirectoryLogin::isMember($attributes, 'cn=guest-wifi, ou=groups, dc=example, dc=com'));
        $this->assertTrue(DirectoryLogin::isMember($attributes, ' CN = Staff , OU=Groups,DC=example,DC=com'));
        $this->assertFalse(DirectoryLogin::isMember($attributes, 'CN=Guest,OU=Groups,DC=example,DC=com'));
        $this->assertFalse(DirectoryLogin::isMember([], 'CN=Staff,OU=Groups,DC=example,DC=com'));
    }

    public function test_user_names_are_normalised(): void
    {
        $this->assertSame('anna', DirectoryLogin::normaliseUsername('  anna '));
        $this->assertSame('anna@example.com', DirectoryLogin::normaliseUsername('anna@example.com'));
        $this->assertNull(DirectoryLogin::normaliseUsername(''));
        $this->assertNull(DirectoryLogin::normaliseUsername("an\0na"));
        $this->assertNull(DirectoryLogin::normaliseUsername("an\nna"));
        $this->assertNull(DirectoryLogin::normaliseUsername(str_repeat('a', 129)));
        $this->assertNull(DirectoryLogin::normaliseUsername(['anna']));
    }

    public function test_admin_input_checks(): void
    {
        $this->assertTrue(SiteDirectoryController::validFilter('(&(objectClass=user)(sAMAccountName={username}))'));
        $this->assertTrue(SiteDirectoryController::validFilter('(uid={username})'));
        $this->assertFalse(SiteDirectoryController::validFilter('(uid=fixed)'));
        $this->assertFalse(SiteDirectoryController::validFilter('uid={username}'));
        $this->assertFalse(SiteDirectoryController::validFilter('(uid={username}))'));
        $this->assertFalse(SiteDirectoryController::validFilter('(uid={username})(cn=x)'));
        $this->assertFalse(SiteDirectoryController::validFilter("(uid={username}\n)"));

        $this->assertSame(['displayName', 'cn'], SiteDirectoryController::attributeList(' displayName ,cn,'));
        $this->assertSame([], SiteDirectoryController::attributeList(''));
        $this->assertNull(SiteDirectoryController::attributeList('display name'));
        $this->assertNull(SiteDirectoryController::attributeList('a,b,c,d'));

        foreach (['DC=example,DC=com', 'CN=Guest-WiFi,OU=Groups,DC=example,DC=com', 'CN=Muster\, Anna,OU=Staff,DC=example,DC=com', 'ou=people, dc=example, dc=com'] as $dn) {
            $this->assertMatchesRegularExpression(SiteDirectoryController::DN_PATTERN, $dn);
        }
        foreach (['example.com', 'CN=', 'CN=a,,DC=b', "CN=a\n"] as $dn) {
            $this->assertDoesNotMatchRegularExpression(SiteDirectoryController::DN_PATTERN, $dn);
        }
        foreach (['dc1.example.com', '10.0.0.5', 'fd00::5'] as $host) {
            $this->assertMatchesRegularExpression(SiteDirectoryController::HOST_PATTERN, $host);
        }
        foreach (['ldaps://dc1.example.com', 'dc1.example.com:636', 'a b', "dc1.example.com\n", 'dc1.example.com ldap://evil.example.com'] as $host) {
            $this->assertDoesNotMatchRegularExpression(SiteDirectoryController::HOST_PATTERN, $host);
        }
    }
}
