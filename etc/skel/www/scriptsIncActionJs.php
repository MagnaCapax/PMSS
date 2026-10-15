<?php
/** ActionJs helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssActionScriptJs')) {
/** Shared browser actions stay in a guiv-delivered PHP file (ADR 0022). */
function pmssActionScriptJs(): string {
 $js = <<<'JS'
/* Shared customer-panel action helpers. Loaded after jQuery. */
var pmssActionNoticeTimer = null;
var pmssMediaStackPollTimer = null;

function pmssShowActionNotice(message, isError) {
    var notice = $('#pmss-action-notice');
    if (notice.length === 0) {
        alert(message);
        return;
    }

    notice.stop(true, true);
    notice.removeClass('pmss-error');
    if (isError) {
        notice.addClass('pmss-error');
    }

    notice.text(message).fadeIn('fast');

    if (pmssActionNoticeTimer !== null) {
        window.clearTimeout(pmssActionNoticeTimer);
    }

    pmssActionNoticeTimer = window.setTimeout(function() {
        notice.fadeOut('slow');
    }, 3200);
}

function pmssSetActionLoading(button, isLoading) {
    var actionButton = $(button);
    var indicator = actionButton.next('.pmss-action-loading');

    if (isLoading) {
        actionButton.attr('disabled', 'disabled');
        if (indicator.length === 0) {
            indicator = $('<span class="pmss-action-loading" aria-live="polite">&#8987; Working...</span>');
            actionButton.after(indicator);
        }
        indicator.show();
        return;
    }

    actionButton.removeAttr('disabled');
    if (indicator.length > 0) {
        indicator.hide();
    }
}

function pmssActionRequest(action, passwordValue) {
    var deferred = $.Deferred();
    var request = {
        url: action.url,
        cache: false,
        data: action.data || null,
        type: action.type || 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        success: function(payload) {
            deferred.resolve(payload);
        },
        error: function(xhr) {
            if (xhr.status === 428 && action.passwordField) {
                var password = window.prompt('Enter your account password to sync qBittorrent WebUI login.');
                if (password !== null) {
                    pmssActionRequest(action, password).done(function(payload) {
                        deferred.resolve(payload);
                    }).fail(function(retryXhr, cancelled) {
                        deferred.reject(retryXhr, cancelled);
                    });
                } else {
                    deferred.reject(xhr, true);
                }
                return;
            }
            deferred.reject(xhr, false);
        }
    };
    if (action.dataType) request.dataType = action.dataType;
    if (action.headers) $.extend(request.headers, action.headers);
    if (action.passwordField && passwordValue !== undefined) {
        request.type = 'POST';
        request.data = $.extend({}, action.data || {});
        request.data[action.passwordField] = passwordValue;
    }
    $.ajax(request);
    return deferred.promise();
}

function pmssRunAction(button, url, successMessage, shouldReload, pendingMessage, passwordFieldName, passwordValue) {
    pmssSetActionLoading(button, true);
    if (pendingMessage) pmssShowActionNotice(pendingMessage, false);

    var action = {
        url: url,
        passwordField: passwordFieldName || (url.indexOf('qbittorrent.php') === 0 ? 'qbittorrentPassword' : '')
    };
    pmssActionRequest(action, passwordValue).done(function() {
        pmssSetActionLoading(button, false);
        if (successMessage) pmssShowActionNotice(successMessage, false);
        if (shouldReload) window.setTimeout(function() { location.reload(true); }, 900);
    }).fail(function(xhr, cancelled) {
        pmssSetActionLoading(button, false);
        if (!cancelled) pmssShowActionNotice('Action failed. Please try again in a moment.', true);
    });
}

function pmssMediaStackPollSchedule(delay) {
    if (pmssMediaStackPollTimer !== null) {
        window.clearTimeout(pmssMediaStackPollTimer);
        pmssMediaStackPollTimer = null;
    }

    if (delay > 0) {
        pmssMediaStackPollTimer = window.setTimeout(pmssMediaStackStatusRefresh, delay);
    }
}

function pmssMediaStackApply(payload) {
    var panel = $('#pmss-media-stack-status');
    var button = $('#pmss-media-stack-start');
    var recoveryButton = $('#pmss-media-stack-recovery');

    if (panel.length > 0 && payload && payload.html) {
        panel.html(payload.html);
    }

    if (button.length > 0 && payload) {
        if (payload.canStart) {
            button.removeAttr('disabled');
        } else {
            button.attr('disabled', 'disabled');
        }
    }

    if (recoveryButton.length > 0 && payload) {
        if (payload.canRestart) {
            recoveryButton.removeAttr('disabled').show();
        } else {
            recoveryButton.attr('disabled', 'disabled').hide();
        }
    }

    pmssMediaStackPollSchedule(payload && payload.poll ? 4000 : 0);
}

function pmssMediaStackStatusRefresh() {
    if ($('#pmss-media-stack-status').length === 0) {
        pmssMediaStackPollSchedule(0);
        return;
    }

    $.ajax({
        url: 'mediaStack.php?action=status',
        dataType: 'json',
        cache: false,
        success: function(payload) {
            pmssMediaStackApply(payload);
        }
    });
}

function pmssMediaStackAction(button, action, pendingMessage, failureMessage) {
    pmssSetActionLoading(button, true);
    pmssShowActionNotice(pendingMessage, false);

    $.ajax({
        url: 'mediaStack.php?action=' + action,
        type: 'POST',
        dataType: 'json',
        cache: false,
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        success: function(payload) {
            pmssSetActionLoading(button, false);
            pmssMediaStackApply(payload);
            if (payload && payload.message) {
                pmssShowActionNotice(payload.message, false);
            }
            if (typeof window.pmssAppsActionComplete === 'function') window.pmssAppsActionComplete();
        },
        error: function(xhr) {
            pmssSetActionLoading(button, false);
            if (xhr && xhr.responseText) {
                pmssMediaStackStatusRefresh();
            }
            pmssShowActionNotice(failureMessage, true);
        }
    });
}

function pmssMediaStackStart(button) {
    pmssMediaStackAction(button, 'start', 'Starting media stack install...', 'Media stack install could not be started from the panel.');
}

function pmssMediaStackStartStopped(button) {
    pmssMediaStackAction(button, 'start-stopped', 'Starting stopped media-stack apps...', 'Stopped media-stack apps could not be started from the panel.');
}

function pmssMediaStackSecureApp(button, app) {
    if (!/^[a-z0-9-]+$/.test(app)) {
        pmssShowActionNotice('Unknown media-stack app.', true);
        return;
    }

    pmssSetActionLoading(button, true);
    pmssShowActionNotice('Securing media-stack app...', false);
    $.ajax({
        url: 'mediaStack.php',
        type: 'POST',
        dataType: 'json',
        cache: false,
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        data: {action: 'confirm-secure-' + app},
        success: function(payload) {
            pmssSetActionLoading(button, false);
            pmssMediaStackApply(payload);
            if (payload && payload.message) {
                pmssShowActionNotice(payload.message, false);
            }
            if (typeof window.pmssAppsActionComplete === 'function') window.pmssAppsActionComplete();
        },
        error: function() {
            pmssSetActionLoading(button, false);
            pmssMediaStackStatusRefresh();
            pmssShowActionNotice('Media-stack app auth could not be configured from the panel.', true);
        }
    });
}
var pmssAppsBusy = {};
function pmssAppsStatusUrl() { return $('.pmss-apps-section').attr('data-status-url'); }
var pmssAppsRefreshTimer;
function pmssAppsUpdatingResponse(xhr) {
    if (!xhr || xhr.status !== 503 || !xhr.responseJSON || xhr.responseJSON.updating !== true) return false;
    window.clearTimeout(pmssAppsRefreshTimer);
    $('.pmss-apps-section .row').each(function() {
        var notice = $(this).find('.row-error');
        if (!notice.length) notice = $('<div class="row-error" role="alert"></div>').appendTo(this);
        notice.text(xhr.responseJSON.message);
    });
    return true;
}
function pmssAppsRefresh(callback) {
    window.clearTimeout(pmssAppsRefreshTimer);
    $.getJSON(pmssAppsStatusUrl(), function(payload) {
        var media = $('#pmss-media-apps'), wasInstalling = media.attr('data-installing') === '1';
        if (payload.poll || wasInstalling || !payload.installed) media.html(payload.mediaHtml);
        media.attr('data-installing', payload.poll ? '1' : '0');
        if (payload.poll || !payload.installed || !payload.canRestart) $('#pmss-start-all').remove();
        else if (!$('#pmss-start-all').length) $('.pmss-apps-section .section-actions').html('<button type="button" class="b" id="pmss-start-all" data-bulk-action="start-stopped">Start all stopped</button>');
        $.each(payload.rows || {}, function(id, html) {
            if (pmssAppsBusy[id]) return;
            var oldRow = $('.pmss-apps-section [data-app="' + id + '"]');
            if (oldRow.length) {
                var oldLog = oldRow.find('.row-log');
                var replacement = $(html);
                if (!oldLog.prop('hidden')) replacement.find('.row-log').html(oldLog.html()).prop('hidden', false);
                oldRow.replaceWith(replacement);
            }
        });
        if (callback) callback(payload);
        pmssAppsRefreshTimer = window.setTimeout(pmssAppsRefresh, payload.poll ? 5000 : 10000);
    }).fail(function(xhr) {
        if (!pmssAppsUpdatingResponse(xhr)) pmssAppsRefreshTimer = window.setTimeout(pmssAppsRefresh, 10000);
    });
}
function pmssAppsWait(id, desired, deadline) {
    window.setTimeout(function() {
        $.getJSON(pmssAppsStatusUrl(), function(payload) {
            var row = $('[data-app="' + id + '"]');
            var state = payload.apps && payload.apps[id] && payload.apps[id].state;
            if (state === desired || Date.now() >= deadline) {
                pmssAppsBusy[id] = false;
                if (payload.rows && payload.rows[id]) row.replaceWith(payload.rows[id]);
                if (state !== desired) $('[data-app="' + id + '"] .row-error').text('Status did not settle within 60 seconds. Check the app log.');
            } else {
                pmssAppsWait(id, desired, deadline);
            }
        }).fail(function(xhr) {
            if (pmssAppsUpdatingResponse(xhr)) {
                pmssAppsBusy[id] = false;
                $('[data-app="' + id + '"] .row-progress').text('');
                $('[data-app="' + id + '"] button').prop('disabled', false);
            } else if (Date.now() < deadline) pmssAppsWait(id, desired, deadline);
            else { pmssAppsBusy[id] = false; $('[data-app="' + id + '"] .row-error').text('Could not read app status.'); }
        });
    }, 2000);
}
function pmssAppsAct(button, id, action) {
    var row = $(button).closest('.row'), kind = id === 'rtorrent' ? 'rtorrent' : (id === 'lighttpd' ? 'web' : (id === 'qBittorrent' || id === 'Deluge' || id === 'rclone' || id === 'slskd' || id === 'copyparty' ? 'managed' : 'media'));
    if (action === 'log') { $.getJSON(pmssAppsStatusUrl().replace('status=1', 'log=' + encodeURIComponent(id)), function(payload) { row.find('.row-log').html(payload.html || 'No log available.').prop('hidden', false); }); return; }
    if (id === 'rtorrent' && action === 'stop' && !window.confirm('Stop rTorrent? All your torrents stop seeding until you start it again.')) return;
    if (action === 'secure') { if (!window.confirm('Secure this app with its default login?')) return; pmssMediaStackSecureApp(button, id); return; }
    var endpoint = kind === 'media' ? 'mediaStack.php?action=app-' + action : (kind === 'rtorrent' ? 'rtorrentRestart.php?action=' + action : (kind === 'web' ? 'lighttpdRestart.php?action=confirm-restart' : (id === 'qBittorrent' ? 'qbittorrent.php' : id === 'Deluge' ? 'deluge.php' : id === 'slskd' ? 'slskd.php' : id === 'copyparty' ? 'copyparty.php' : 'rclone.php') + '?action=' + action));
    var desired = action === 'stop' || action === 'disable' ? 'off' : 'running';
    pmssAppsBusy[id] = true;
    row.find('.row-error').text(''); row.find('.row-progress').text(action === 'stop' || action === 'disable' ? 'Stopping…' : 'Starting…');
    row.find('button').prop('disabled', true);
    pmssActionRequest({url:endpoint, data:kind === 'media' ? {app:id} : null, passwordField:id === 'qBittorrent' ? 'qbittorrentPassword' : ''}).done(function() {
        if (kind === 'web') { pmssAppsBusy[id] = false; row.find('.row-progress').text('Restart requested.'); row.find('button').prop('disabled', false); return; }
        pmssAppsWait(id, desired, Date.now() + ((id === 'slskd' || id === 'copyparty') ? 120000 : 60000));
    }).fail(function(xhr, cancelled) {
        pmssAppsBusy[id] = false; row.find('.row-progress').text(''); row.find('button').prop('disabled', false);
        if (!cancelled) row.find('.row-error').text((xhr.responseJSON && xhr.responseJSON.message) || 'Action failed. Please try again.');
    });
}
$(document).on('click', '.row[data-app] button[data-action]', function() { pmssAppsAct(this, $(this).closest('.row').attr('data-app'), $(this).attr('data-action')); });
$(document).on('click', '#pmss-start-all[data-bulk-action]', function() { pmssAppsBulk(this, $(this).attr('data-bulk-action')); });
function pmssAppsBulk(button, action) { pmssMediaStackAction(button, action, 'Starting stopped apps…', 'Could not start stopped apps.'); }
function pmssAppsInstall(button) { pmssMediaStackStart(button); }
function pmssAppsRestartAll(button) {
    $(button).prop('disabled', true);
    $.getJSON(pmssAppsStatusUrl(), function(status) {
        var actions = [], failures = 0, apps = status.apps || {};
        if (apps.rtorrent && apps.rtorrent.state !== 'off') actions.push({url:'rtorrentRestart.php?action=restart'});
        $.each({qBittorrent:'qbittorrent.php', Deluge:'deluge.php', rclone:'rclone.php', slskd:'slskd.php', copyparty:'copyparty.php'}, function(id, endpoint) {
            if (apps[id] && apps[id].state !== 'off') actions.push({url:endpoint + '?action=restart', passwordField:id === 'qBittorrent' ? 'qbittorrentPassword' : ''});
        });
        if (status.installed && status.canRestart && $('#pmss-start-all').length) actions.push({url:'mediaStack.php?action=start-stopped'});
        if (apps.lighttpd) actions.push({url:'lighttpdRestart.php?action=confirm-restart'});
        function next() {
            if (!actions.length) {
                $(button).prop('disabled', false);
                pmssShowActionNotice(failures ? 'Some restart requests failed.' : 'Restart requests sent.', !!failures);
                pmssAppsRefresh();
                return;
            }
            pmssActionRequest(actions.shift()).done(next).fail(function() { failures++; next(); });
        }
        next();
    }).fail(function() { $(button).prop('disabled', false); pmssShowActionNotice('Could not read current app status.', true); });
}
window.pmssAppsActionComplete = function() { pmssAppsRefresh(); };
$(function() { if ($('.pmss-apps-section').length) pmssAppsRefreshTimer = window.setTimeout(pmssAppsRefresh, 10000); });
JS;
 return $js."\n";
}
}
