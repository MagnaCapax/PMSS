-- PMSS opt-in panel session-cookie gate for per-user lighttpd.
-- Return nil to delegate to the existing htpasswd Basic auth path.

local COOKIE_NAME = "pmss_panel_session"
local SESSION_FILE = "panel-session"
local LOGIN_SCRIPT = "panelSessionLogin.php"
local LOGOUT_SCRIPT = "panelSessionLogout.php"
local ABSOLUTE_LIFETIME = 28800
local IDLE_TIMEOUT = 1800

local req_env = lighty.req_env or (lighty.r and lighty.r.req_env) or {}

local function request_header(name)
    local request = lighty.request or {}
    return request[name] or request[string.lower(name)] or ""
end

local function request_path()
    local env = lighty.env or {}
    local path = env["uri.path"] or env["request.uri"] or ""
    return (path:gsub("%?.*$", ""))
end

local function panel_user(path)
    local user = path:match("^/user%-([a-z][a-z0-9]*)($|/)")
    if not user or #user > 8 then
        return nil
    end
    return user
end

local function log_event(user, auth, detail)
    print("pmss panelSession user=" .. user .. " auth=" .. auth .. " detail=" .. detail)
end

local function set_remote_user(user, auth)
    req_env["REMOTE_USER"] = user
    req_env["PMSS_PANEL_AUTH"] = auth
end

local function valid_hex(value, length)
    return type(value) == "string" and #value == length and value:match("^[0-9a-f]+$") ~= nil
end

local function cookie_value(header, name)
    for part in string.gmatch(header or "", "([^;]+)") do
        local key, value = part:match("^%s*([^=]+)=([^;]*)%s*$")
        if key == name then
            return value
        end
    end
    return nil
end

local function path_exists(path)
    local statter = lighty.stat or (lighty.c and lighty.c.stat)
    if statter then
        local ok, stat = pcall(statter, path)
        return ok and stat ~= nil
    end

    local file = io.open(path, "r")
    if file then
        file:close()
        return true
    end
    return false
end

local function read_session(path)
    local file, err = io.open(path, "r")
    if not file then
        return nil, err or "open_failed"
    end

    local content = file:read(4097) or ""
    file:close()
    if #content > 4096 then
        return nil, "oversize"
    end

    local session = {}
    for line in content:gmatch("[^\r\n]+") do
        local key, value = line:match("^([a-z]+)=([A-Za-z0-9]*)$")
        if key then
            session[key] = value
        end
    end
    return session, nil
end

local function write_session_seen(path, session, now)
    local file = io.open(path, "w")
    if not file then
        return false
    end

    file:write("id=" .. (session["id"] or "") .. "\n")
    file:write("csrf=" .. (session["csrf"] or "") .. "\n")
    file:write("created=" .. tostring(session["created"] or "0") .. "\n")
    file:write("seen=" .. tostring(now) .. "\n")
    file:write("failed=" .. tostring(session["failed"] or "0") .. "\n")
    file:close()
    return true
end

local function session_valid(session, session_id, now)
    local created = tonumber(session["created"] or "")
    local seen = tonumber(session["seen"] or "")
    if not valid_hex(session["id"] or "", 64) or session["id"] ~= session_id then
        return false
    end
    if not created or not seen or now < created or now < seen then
        return false
    end
    return now - created <= ABSOLUTE_LIFETIME and now - seen <= IDLE_TIMEOUT
end

local function accepts_html()
    return string.find(string.lower(request_header("Accept")), "text/html", 1, true) ~= nil
end

local function escape_url(value)
    return (value:gsub("([^A-Za-z0-9._~/%-])", function(char)
        return string.format("%%%02X", string.byte(char))
    end))
end

local path = request_path()
local user = panel_user(path)
if not user then
    return nil
end

local prefix = "/user-" .. user
local relative = string.sub(path, #prefix + 1)
if relative == "/" .. LOGIN_SCRIPT or relative == "/" .. LOGOUT_SCRIPT then
    set_remote_user(user, "login")
    log_event(user, "login", "public-handler")
    return nil
end

local session_id = cookie_value(request_header("Cookie"), COOKIE_NAME)
if valid_hex(session_id or "", 64) then
    local session_path = "/home/" .. user .. "/.lighttpd/" .. SESSION_FILE
    if path_exists(session_path) then
        local session, err = read_session(session_path)
        if session == nil then
            log_event(user, "basic", "session-read-error-" .. err)
            return nil
        end

        local now = os.time()
        if session_valid(session, session_id, now) then
            set_remote_user(user, "cookie")
            if not write_session_seen(session_path, session, now) then
                log_event(user, "cookie", "seen-update-failed")
            else
                log_event(user, "cookie", "accepted")
            end
            return nil
        end
    end
end

local authorization = request_header("Authorization")
if authorization ~= "" then
    log_event(user, "basic", "delegated")
    return nil
end

if accepts_html() then
    lighty.header["Location"] = prefix .. "/" .. LOGIN_SCRIPT .. "?return=" .. escape_url(path)
    log_event(user, "redirect", "login")
    return 302
end

log_event(user, "basic", "required")
return nil
