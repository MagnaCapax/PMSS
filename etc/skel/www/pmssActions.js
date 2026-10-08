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
