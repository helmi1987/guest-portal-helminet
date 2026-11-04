<?php

namespace App\Nas\Generic;

/**
 * Vendor presets of the generic RADIUS captive-portal driver (decision 2026-11-04, v0.28.0). A preset only
 * prefills the mapping on the site page; the admin checks and saves it. ALL PRESETS ARE UNVERIFIED: they
 * follow public vendor and community documentation, nobody tested them against the real devices (the NAS
 * simulator in tests/nas-sim checks the driver against these conventions, not the vendors). Sources and
 * open points per preset below and in docs/generic-captive-portal.md.
 */
final class Presets
{
    /**
     * Cisco AireOS WLC / Catalyst 9800, external web authentication.
     * Sources: https://www.cisco.com/c/en/us/support/docs/wireless/catalyst-9800-series-wireless-controllers/217457-configure-and-troubleshoot-external-web.html
     *          https://github.com/stuartst/cisco-wlc-captive-portal/blob/master/README.md (community, AireOS)
     * Unverified: the success field (AireOS sample page: redirect_url; the 9800 document shows redirectUrl),
     * err_flag; on the 9800 the parameters are only appended with "redirect append ap-mac/client-mac/wlan"
     * and may carry other names; switch_url must be HTTPS (virtual IP with a certificate).
     */
    public const CISCO_WLC = 'cisco_wlc';

    /**
     * Aruba Instant / ArubaOS external captive portal.
     * Sources: https://www.manualslib.com/manual/1483534/Aruba-Iap-335.html?page=133 (redirect parameters)
     *          https://www.manualslib.com/manual/1483534/Aruba-Iap-335.html?page=118 (walled garden: whitelist)
     *          https://community.networking.hpe.com/discussion/external-captive-portal-login-url (login form)
     * Unverified: the url field in the login POST, /cgi-bin/login vs /swarm.cgi, the login host (must match
     * the AP certificate; securelogin.arubanetworks.com or the switchip parameter).
     */
    public const ARUBA = 'aruba';

    /**
     * pfSense captive portal with an uploaded portal page (this driver's login page).
     * Sources: https://docs.netgate.com/pfsense/en/latest/captiveportal/index.html
     *          https://forum.netgate.com/post/826261 (custom form: auth_user, auth_pass, redirurl, accept, zone)
     * Unverified: the page variables $CLIENT_MAC$ / $CLIENT_IP$ (without a MAC the portal cannot bind the
     * device), the HTTPS login port of the zone.
     */
    public const PFSENSE = 'pfsense';

    /**
     * OPNsense captive portal (observed on OPNsense 26.7, 2026-11-04). OPNsense has no external login page: the
     * zone always redirects to its own template (http://<zone ip>:8000/index.html?redirurl=..). A custom template
     * (docs/opnsense-captive-template/index.html) asks /api/captiveportal/access/status/<zone>/ for IP and MAC and
     * forwards the guest to the portal with nas_host, zone, mac, ip and redirurl. OPNsense identifies the guest by
     * its source IP, so the guest's browser logs in: POST /api/captiveportal/access/logon/<zone>/ with user and
     * password (default, the guest then sees OPNsense's JSON answer), or method "fragment": the portal sends the
     * guest back to the template with #user=..&password=..&redirurl=.. and the template posts and forwards.
     * RADIUS from OPNsense: PAP, Calling-Station-Id = MAC, no Framed-IP-Address; no Disconnect-Request support
     * (nothing listens on 3799): access ends with Session-Timeout.
     * Sources: https://docs.opnsense.org/manual/captiveportal.html (ports 8000+zone / 9000+zone)
     *          https://github.com/opnsense/core/blob/master/src/opnsense/mvc/app/controllers/OPNsense/CaptivePortal/Api/AccessController.php
     */
    public const OPNSENSE = 'opnsense';

    /**
     * CoovaChilli (OpenWrt) UAM, PAP (observed on CoovaChilli 1.9, 2026-11-04: redirect parameters, logon by GET
     * without uamsecret with the password XOR the raw challenge, dashed MACs, Disconnect-NAK for unknown sessions).
     * Sources: https://radiusdesk.com/wiki24/radius/login_dynamic_keys (redirect parameters)
     *          https://sourceforge.net/p/hotcakes/wiki/yfi_explain_uam (logon URL)
     *          https://github.com/coova/coova-chilli/blob/master/src/redir.c (password encoding, redirurl)
     * The password (zero-padded to 16 bytes) is hex(password XOR MD5(challenge + uamsecret)), or XOR the raw
     * challenge bytes without uamsecret, done by the driver; the uamsecret goes into the handoff secret. CHAP
     * is not supported. Unverified: redirurl as the success field, the walled-garden option names.
     */
    public const COOVACHILLI = 'coovachilli';

    /**
     * Ruckus Unleashed / ZoneDirector hotspot (WISPr) WLAN with an external portal.
     * Sources: https://community.ruckuswireless.com/discussion/47226 (login URL :9998 https / :9997 http)
     *          https://forum.netgate.com/post/1132205 (POST fields username, password, client_mac, uip, url)
     * Unverified: the redirect parameter names (client_mac, uip, url, sip, nbiIP) and their MAC format;
     * SmartZone encrypts MAC and IP in the redirect by default and logs in through its northbound
     * interface instead (not covered).
     */
    public const RUCKUS = 'ruckus';

    public const CUSTOM = 'custom';

    /** Preset => mapping (GenericSettings fields). */
    public const PRESETS = [
        self::CISCO_WLC => [
            'param_mac' => 'client_mac', 'param_ip' => '', 'param_login_url' => 'switch_url', 'login_url' => '',
            'param_orig' => 'redirect', 'param_result' => '', 'method' => 'POST', 'field_username' => 'username', 'field_password' => 'password',
            'field_success' => 'redirect_url', 'extra_fields' => "buttonClicked=4\nerr_flag=0", 'password_encoding' => 'plain',
            'allow_http' => false, 'login_page_template' => '',
        ],
        self::ARUBA => [
            'param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => '',
            'login_url' => 'https://securelogin.arubanetworks.com/cgi-bin/login', 'param_orig' => 'url', 'param_result' => '', 'method' => 'POST',
            'field_username' => 'user', 'field_password' => 'password', 'field_success' => 'url',
            'extra_fields' => 'cmd=authenticate', 'password_encoding' => 'plain', 'allow_http' => false, 'login_page_template' => '',
        ],
        self::PFSENSE => [
            'param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => 'login_url', 'login_url' => '',
            'param_orig' => 'redirurl', 'param_result' => '', 'method' => 'POST', 'field_username' => 'auth_user', 'field_password' => 'auth_pass',
            'field_success' => 'redirurl', 'extra_fields' => "accept=Continue\nzone={zone}", 'password_encoding' => 'plain',
            'allow_http' => false,
            'login_page_template' => 'login_url=$PORTAL_ACTION$&zone=$PORTAL_ZONE$&redirurl=$PORTAL_REDIRURL$&mac=$CLIENT_MAC$&ip=$CLIENT_IP$',
        ],
        self::OPNSENSE => [
            'param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => '',
            'login_url' => 'http://{nas_host}/api/captiveportal/access/logon/{zone}/', 'param_orig' => 'redirurl', 'param_result' => '',
            'method' => 'POST', 'field_username' => 'user', 'field_password' => 'password', 'field_success' => 'redirurl',
            'extra_fields' => '', 'password_encoding' => 'plain', 'allow_http' => true, 'login_page_template' => '',
        ],
        self::COOVACHILLI => [
            'param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => '', 'login_url' => 'http://{uamip}:{uamport}/logon',
            'param_orig' => 'userurl', 'param_result' => 'res', 'method' => 'GET', 'field_username' => 'username', 'field_password' => 'password',
            'field_success' => 'userurl', 'extra_fields' => '', 'password_encoding' => 'chilli', 'allow_http' => true,
            'login_page_template' => '',
        ],
        self::RUCKUS => [
            'param_mac' => 'client_mac', 'param_ip' => 'uip', 'param_login_url' => '', 'login_url' => 'https://{sip}:9998/login',
            'param_orig' => 'url', 'param_result' => '', 'method' => 'POST', 'field_username' => 'username', 'field_password' => 'password',
            'field_success' => 'url', 'extra_fields' => "client_mac={client_mac}\nuip={uip}", 'password_encoding' => 'plain',
            'allow_http' => false, 'login_page_template' => '',
        ],
        self::CUSTOM => [
            'param_mac' => 'mac', 'param_ip' => 'ip', 'param_login_url' => '', 'login_url' => '', 'param_orig' => '', 'param_result' => '',
            'method' => 'POST', 'field_username' => 'username', 'field_password' => 'password', 'field_success' => 'dst',
            'extra_fields' => '', 'password_encoding' => 'plain', 'allow_http' => false, 'login_page_template' => '',
        ],
    ];

    /** Disconnect-Request attributes a preset sets (others: GenericSettings::DEFAULT_DISCONNECT); [] = not supported. */
    public const DISCONNECT = [self::OPNSENSE => []];

    /** Sample redirect values per preset for "Simulate redirect" (generic examples only). */
    public const SAMPLES = [
        self::CISCO_WLC => ['client_mac' => 'aa:bb:cc:00:00:01', 'ap_mac' => 'aa:bb:cc:00:00:99', 'wlan' => 'Guest',
            'switch_url' => 'https://{hotspot}/login.html', 'redirect' => 'http://example.com/'],
        self::ARUBA => ['cmd' => 'login', 'mac' => 'aa:bb:cc:00:00:01', 'essid' => 'Guest', 'ip' => '10.0.0.20',
            'apname' => 'ap-1', 'url' => 'http://example.com/'],
        self::PFSENSE => ['login_url' => 'https://{hotspot}:8003/index.php?zone=guest', 'zone' => 'guest',
            'redirurl' => 'http://example.com/', 'mac' => 'aa:bb:cc:00:00:01', 'ip' => '10.0.0.20'],
        self::OPNSENSE => ['nas_host' => '{hotspot}:8000', 'zone' => '0', 'mac' => 'aa:bb:cc:00:00:01', 'ip' => '10.0.0.20',
            'redirurl' => 'http://example.com/'],
        self::COOVACHILLI => ['res' => 'notyet', 'uamip' => '{hotspot}', 'uamport' => '3990',
            'challenge' => '0123456789abcdef0123456789abcdef', 'called' => 'AA-BB-CC-00-00-99', 'mac' => 'AA-BB-CC-00-00-01',
            'ip' => '10.0.0.20', 'nasid' => 'nas-a', 'sessionid' => '0000000000000001', 'userurl' => 'http://example.com/'],
        self::RUCKUS => ['client_mac' => 'aa:bb:cc:00:00:01', 'uip' => '10.0.0.20', 'sip' => '{hotspot}', 'nbiIP' => '{hotspot}',
            'url' => 'http://example.com/', 'ssid' => 'Guest'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }
}
