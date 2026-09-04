<?php
namespace PMSS\Tests;

class PanelSessionGateSourceTest extends TestCase
{
    public function testLuaGateSourcePreservesCookieOrBasicDecisionOrder(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/lighttpd/panelSessionGate.lua');

        $this->assertStringContainsAllStrings([
            'local COOKIE_NAME = "pmss_panel_session"',
            'local SESSION_FILE = "panel-session"',
            'local ABSOLUTE_LIFETIME = 28800',
            'local IDLE_TIMEOUT = 1800',
            'req_env["REMOTE_USER"] = user',
            'req_env["PMSS_PANEL_AUTH"] = auth',
            'log_event(user, "cookie", "accepted")',
            'log_event(user, "basic", "delegated")',
            'lighty.header["Location"] = prefix .. "/" .. LOGIN_SCRIPT',
            'return 302',
        ], $source);
        $this->assertOrderedStrings([
            'local session_id = cookie_value(request_header("Cookie"), COOKIE_NAME)',
            'if session_valid(session, session_id, now) then',
            'set_remote_user(user, "cookie")',
            'local authorization = request_header("Authorization")',
            'if authorization ~= "" then',
            'if accepts_html() then',
            'return 302',
            'log_event(user, "basic", "required")',
        ], $source);
        $this->assertStringContainsString(
            "if authorization ~= \"\" then\n    log_event(user, \"basic\", \"delegated\")\n    return nil\nend",
            $source
        );
        $this->assertStringContainsString(
            "log_event(user, \"basic\", \"required\")\nreturn nil",
            $source
        );
    }

    public function testLuaGateAllowsOnlyLoginAndLogoutPublicHandlersInsideUserZone(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/lighttpd/panelSessionGate.lua');

        $this->assertStringContainsAllStrings([
            'local LOGIN_SCRIPT = "panelSessionLogin.php"',
            'local LOGOUT_SCRIPT = "panelSessionLogout.php"',
            'relative == "/" .. LOGIN_SCRIPT or relative == "/" .. LOGOUT_SCRIPT',
            'set_remote_user(user, "login")',
        ], $source);
        $this->assertStringNotContainsString('/webdav-', $source, 'WebDAV must stay outside the Lua gate');
    }
}
