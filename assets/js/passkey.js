'use strict';

/**
 * WebAuthn passkey support.
 *
 * Exposes window.NAILS.PASSKEY so that other components - notably the MFA driver,
 * which ships no JavaScript of its own - can run a ceremony without repeating any
 * of the encoding work.
 */
(function() {

    var API_BASE = 'api/auth/passkey/';

    var conditionalAbortController = null;

    /**
     * Below this, a rejection cannot be somebody deciding not to continue
     */
    var MIN_HUMAN_RESPONSE_MS = 250;

    /**
     * How long to wait before saying so in the console when no prompt has appeared
     */
    var CEREMONY_WARN_MS = 10000;

    /**
     * The backstop. A hooked `navigator.credentials` can leave the promise pending
     * for ever - no prompt, no rejection, nothing to report - so the ceremony is
     * abandoned once the authenticator's own timeout has certainly passed.
     */
    var CEREMONY_TIMEOUT_MS = 60000;

    // --------------------------------------------------------------------------

    /**
     * Whether this browser can do WebAuthn at all
     *
     * @return {boolean}
     */
    function isSupported() {
        return typeof window.PublicKeyCredential === 'function' &&
            !!(navigator.credentials && navigator.credentials.create && navigator.credentials.get);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the browser can offer a passkey from a form field's own dropdown
     *
     * @return {Promise<boolean>}
     */
    function isConditionalMediationAvailable() {
        if (!isSupported() || typeof window.PublicKeyCredential.isConditionalMediationAvailable !== 'function') {
            return Promise.resolve(false);
        }
        return window.PublicKeyCredential.isConditionalMediationAvailable().catch(function() {
            return false;
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Whether this device has a built in authenticator to enrol
     *
     * @return {Promise<boolean>}
     */
    function isPlatformAuthenticatorAvailable() {
        if (!isSupported() ||
            typeof window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') {
            return Promise.resolve(false);
        }
        return window.PublicKeyCredential
            .isUserVerifyingPlatformAuthenticatorAvailable()
            .catch(function() {
                return false;
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Decodes a base64url string into an ArrayBuffer
     *
     * @param {string} value The base64url encoded value
     *
     * @return {ArrayBuffer}
     */
    function decode(value) {
        var padded = value.replace(/-/g, '+').replace(/_/g, '/');
        while (padded.length % 4) {
            padded += '=';
        }
        var binary = window.atob(padded);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    // --------------------------------------------------------------------------

    /**
     * Encodes an ArrayBuffer as a base64url string
     *
     * @param {ArrayBuffer} buffer The buffer to encode
     *
     * @return {string}
     */
    function encode(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    // --------------------------------------------------------------------------

    /**
     * Serialises a credential the way PublicKeyCredential.toJSON() would
     *
     * Used in place of toJSON() where the browser does not implement it yet, so that
     * the server only ever sees one shape.
     *
     * @param {PublicKeyCredential} credential The credential to serialise
     *
     * @return {Object}
     */
    function toJSON(credential) {
        if (typeof credential.toJSON === 'function') {
            return credential.toJSON();
        }

        var response = credential.response;
        var out = {
            'id': credential.id,
            'rawId': encode(credential.rawId),
            'type': credential.type,
            'authenticatorAttachment': credential.authenticatorAttachment || null,
            'clientExtensionResults': credential.getClientExtensionResults
                ? credential.getClientExtensionResults()
                : {},
            'response': {
                'clientDataJSON': encode(response.clientDataJSON)
            }
        };

        if (response.attestationObject) {
            out.response.attestationObject = encode(response.attestationObject);
            out.response.transports = typeof response.getTransports === 'function'
                ? response.getTransports()
                : [];
        } else {
            out.response.authenticatorData = encode(response.authenticatorData);
            out.response.signature = encode(response.signature);
            out.response.userHandle = response.userHandle ? encode(response.userHandle) : null;
        }

        return out;
    }

    // --------------------------------------------------------------------------

    /**
     * Turns the server's creation options back into the binary the browser wants
     *
     * @param {Object} options The options as sent by the server
     *
     * @return {Object}
     */
    function decodeCreationOptions(options) {
        var decoded = Object.assign({}, options);

        decoded.challenge = decode(options.challenge);
        decoded.user = Object.assign({}, options.user, {'id': decode(options.user.id)});

        if (Array.isArray(options.excludeCredentials)) {
            decoded.excludeCredentials = options.excludeCredentials.map(function(item) {
                return Object.assign({}, item, {'id': decode(item.id)});
            });
        }

        return decoded;
    }

    // --------------------------------------------------------------------------

    /**
     * Turns the server's request options back into the binary the browser wants
     *
     * @param {Object} options The options as sent by the server
     *
     * @return {Object}
     */
    function decodeRequestOptions(options) {
        var decoded = Object.assign({}, options);

        decoded.challenge = decode(options.challenge);

        if (Array.isArray(options.allowCredentials)) {
            decoded.allowCredentials = options.allowCredentials.map(function(item) {
                return Object.assign({}, item, {'id': decode(item.id)});
            });
        }

        return decoded;
    }

    // --------------------------------------------------------------------------

    /**
     * Fails a ceremony which never settles
     *
     * An extension which intercepts `navigator.credentials` can swallow the call
     * entirely, leaving a promise which neither resolves nor rejects. Without this
     * the user is left looking at a disabled button and the console stays silent.
     *
     * @param {Promise<Object>} promise  The ceremony
     * @param {number}          timeout  How long the options gave the authenticator
     * @param {AbortController} controls Lets the pending call be abandoned
     *
     * @return {Promise<Object>}
     */
    function withWatchdog(promise, timeout, controls) {

        var settled = false;
        var mark = function() {
            settled = true;
        };

        promise.then(mark, mark);

        var warn = window.setTimeout(function() {
            if (!settled) {
                console.warn(
                    '[passkey] no prompt after ' + (CEREMONY_WARN_MS / 1000) + 's. The browser has ' +
                    'not opened a passkey prompt and has not reported an error; a password manager ' +
                    'or other extension may be intercepting navigator.credentials.'
                );
            }
        }, CEREMONY_WARN_MS);

        var expire = new Promise(function(resolve, reject) {
            window.setTimeout(function() {
                if (settled) {
                    return;
                }
                if (controls) {
                    try {
                        controls.abort();
                    } catch (e) {
                        //  Nothing more can be done; the rejection below is what matters
                    }
                }
                var error = new Error('The passkey prompt never opened.');
                error.passkeyRefused = true;
                error.passkeyTimedOut = true;
                reject(error);
            }, Math.max(timeout || 0, CEREMONY_TIMEOUT_MS));
        });

        return Promise.race([promise, expire]).finally(function() {
            window.clearTimeout(warn);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Records why a ceremony failed
     *
     * The browser reports "the user declined" and "the browser refused to ask" with
     * the same NotAllowedError, so the two are told apart here: a page which is not
     * focused never got to ask, and nobody declines a prompt in a quarter of a
     * second. Without this an environment problem looks exactly like a cancellation,
     * which is to say it looks like nothing happening at all.
     *
     * @param {Error}  error     The error the browser raised
     * @param {number} startedAt When the ceremony was started
     *
     * @return {Error}
     */
    function annotateCeremonyError(error, startedAt) {

        var elapsed = Date.now() - startedAt;
        var focused = document.hasFocus();

        if (error && error.name === 'NotAllowedError' && (!focused || elapsed < MIN_HUMAN_RESPONSE_MS)) {
            error.passkeyRefused = true;
            error.passkeyUnfocused = !focused;
        }

        console.warn('[passkey] ceremony failed', {
            'name': error && error.name,
            'message': error && error.message,
            'elapsedMs': elapsed,
            'documentFocused': focused
        });

        return error;
    }

    // --------------------------------------------------------------------------

    /**
     * Runs a registration ceremony
     *
     * @param {Object} options The decoded creation options
     *
     * @return {Promise<Object>}
     */
    function create(options) {

        var startedAt = Date.now();
        var controls = new AbortController();

        var ceremony = navigator.credentials
            .create({'publicKey': decodeCreationOptions(options), 'signal': controls.signal})
            .then(function(credential) {
                if (!credential) {
                    throw new Error('cancelled');
                }
                return toJSON(credential);
            });

        return withWatchdog(ceremony, options.timeout, controls)
            .catch(function(error) {
                throw annotateCeremonyError(error, startedAt);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Runs an authentication ceremony
     *
     * @param {Object} options  The decoded request options
     * @param {Object} settings Optional mediation and abort signal
     *
     * @return {Promise<Object>}
     */
    function get(options, settings) {
        settings = settings || {};

        var request = {'publicKey': decodeRequestOptions(options)};

        if (settings.mediation) {
            request.mediation = settings.mediation;
        }

        /**
         * The conditional path supplies its own signal so the button can abandon it;
         * the modal path gets one of its own so the watchdog has something to pull.
         */
        var controls = settings.signal ? null : new AbortController();

        request.signal = settings.signal || controls.signal;

        var startedAt = Date.now();

        var ceremony = navigator.credentials
            .get(request)
            .then(function(credential) {
                if (!credential) {
                    throw new Error('cancelled');
                }
                return toJSON(credential);
            });

        /**
         * A conditional request legitimately waits for as long as the user ignores the
         * field, so only the modal one is watched.
         */
        return (settings.mediation === 'conditional'
            ? ceremony
            : withWatchdog(ceremony, options.timeout, controls)
        ).catch(function(error) {
            throw annotateCeremonyError(error, startedAt);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Posts JSON to one of the passkey endpoints
     *
     * @param {string} endpoint The endpoint name
     * @param {Object} body     The request body
     *
     * @return {Promise<Object>}
     */
    function post(endpoint, body) {
        return request('POST', endpoint, body);
    }

    // --------------------------------------------------------------------------

    /**
     * Talks to one of the passkey endpoints
     *
     * @param {string} method   The HTTP method
     * @param {string} endpoint The endpoint name
     * @param {Object} body     The request body
     *
     * @return {Promise<Object>}
     */
    function request(method, endpoint, body) {
        return window
            .fetch(siteUrl(API_BASE + endpoint), {
                'method': method,
                'credentials': 'same-origin',
                'headers': {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                'body': method === 'GET' ? undefined : JSON.stringify(body || {})
            })
            .then(function(response) {

                /**
                 * An older MFA module may still redirect inside setLoginData(); a
                 * redirected reply is treated as navigation rather than as an error
                 * the user has to read.
                 */
                if (response.redirected) {
                    window.location.assign(response.url);
                    return new Promise(function() {});
                }

                return response.text().then(function(text) {

                    var payload = null;

                    try {
                        payload = JSON.parse(text);
                    } catch (e) {
                        payload = null;
                    }

                    if (response.ok && payload) {
                        return payload.data || {};
                    }

                    /**
                     * A failure here is usually a server error rendered as an HTML page,
                     * which tells the user nothing. Report what can be said usefully and
                     * put the body in the console for whoever is debugging it.
                     */
                    var message = payload && payload.error
                        ? payload.error
                        : 'Sorry, something went wrong (HTTP ' + response.status + '). Please try again.';

                    var error = new Error(message);
                    error.status = response.status;
                    error.body = text;

                    console.error('[passkey] ' + method + ' ' + endpoint + ' failed', {
                        'status': response.status,
                        'body': text.slice(0, 2000)
                    });

                    throw error;
                });
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Builds an absolute URL for a site path
     *
     * @param {string} path The path, relative to the site root
     *
     * @return {string}
     */
    function siteUrl(path) {
        var form = document.querySelector('[data-passkey-site-url]');
        var base = form ? form.getAttribute('data-passkey-site-url') : '/';
        return (base || '/').replace(/\/+$/, '') + '/' + path;
    }

    // --------------------------------------------------------------------------

    /**
     * Registers a new passkey against the logged in account
     *
     * @param {Object} settings Optionally carries a label for the new passkey
     *
     * @return {Promise<Object>}
     */
    function register(settings) {
        settings = settings || {};

        return post('register', {})
            .then(function(data) {
                return create(data.options);
            })
            .then(function(credential) {
                return post('attest', {
                    'label': settings.label || null,
                    'credential': credential
                });
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Signs in with a passkey
     *
     * @param {Object} settings Mediation, remember-me, and return URL
     *
     * @return {Promise<void>}
     */
    function login(settings) {
        settings = settings || {};

        var signal = null;

        if (settings.conditional) {
            conditionalAbortController = new AbortController();
            signal = conditionalAbortController.signal;
        }

        return post('challenge', {})
            .then(function(data) {
                return get(data.options, {
                    //  Only the conditional path names a mediation; the modal one takes the default
                    'mediation': settings.conditional ? 'conditional' : null,
                    'signal': signal
                });
            })
            .then(function(credential) {
                return post('assert', {
                    'credential': credential,
                    'remember': !!settings.remember,
                    'return_to': settings.returnTo || null
                });
            })
            .then(function(data) {
                window.location.assign(data.redirect);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Abandons any conditional request which is waiting in the background
     *
     * Called before starting an explicit ceremony, because a browser will only
     * entertain one outstanding request at a time and the newest should win.
     *
     * @return {void}
     */
    function abortConditional() {
        if (conditionalAbortController) {
            conditionalAbortController.abort();
            conditionalAbortController = null;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Whether an error is the user declining, rather than something going wrong
     *
     * @param {Error} error The error to test
     *
     * @return {boolean}
     */
    function isCancellation(error) {

        if (!error || error.passkeyRefused) {
            return false;
        }

        return error.name === 'NotAllowedError' || error.name === 'AbortError' ||
            error.message === 'cancelled';
    }

    // --------------------------------------------------------------------------

    /**
     * Turns an error into something a user can act on
     *
     * The browser reports ceremony failures as DOMExceptions whose messages are
     * written for developers, so the well-known ones are named explicitly.
     *
     * @param {Error} error The error to describe
     *
     * @return {string}
     */
    function describeError(error) {

        if (error && error.passkeyUnfocused) {
            return 'The passkey prompt could not open because this page was not in focus. ' +
                'Click anywhere on the page, then try again.';
        }

        if (error && error.passkeyTimedOut) {
            return 'The passkey prompt never opened. A password manager or browser extension ' +
                'may be blocking it; please try again, or sign in with your password.';
        }

        if (error && error.passkeyRefused) {
            return 'Your browser could not open the passkey prompt. This is often a browser ' +
                'extension interfering; please try again, or sign in with your password.';
        }

        switch (error && error.name) {

            case 'InvalidStateError':
                return 'This device already has a passkey for your account.';

            case 'SecurityError':
                return 'Passkeys need a secure (HTTPS) connection to this site.';

            case 'NotSupportedError':
            case 'ConstraintError':
                return 'This device cannot create the kind of passkey this site asks for.';

            case 'UnknownError':
                return 'This device could not complete the request. Please try again.';

            default:
                return (error && error.message)
                    ? error.message
                    : 'Sorry, something went wrong. Please try again.';
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Marks a control as waiting on the browser
     *
     * A ceremony can sit for a long time - the user may be reaching for their phone -
     * so the control says what it is doing rather than just going dim.
     *
     * @param {Element} control The control to mark
     * @param {boolean} busy    Whether the ceremony is running
     *
     * @return {void}
     */
    function setBusy(control, busy) {

        if (busy) {
            control.setAttribute('data-passkey-idle-label', control.innerHTML);
            control.innerHTML = control.getAttribute('data-passkey-busy-label') ||
                'Waiting for your passkey&hellip;';
        } else if (control.hasAttribute('data-passkey-idle-label')) {
            control.innerHTML = control.getAttribute('data-passkey-idle-label');
            control.removeAttribute('data-passkey-idle-label');
        }

        control.disabled = busy;
        control.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    // --------------------------------------------------------------------------

    /**
     * Shows an error near the control which produced it
     *
     * @param {Element} origin  The control the user interacted with
     * @param {string}  message The message to show
     *
     * @return {void}
     */
    function showError(origin, message) {
        var target = origin && origin.closest ? origin.closest('div, form, td') : null;
        var element = (target || document).querySelector('[data-passkey-error]');

        if (element) {
            element.textContent = message;
            element.hidden = false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Hides any error previously shown near a control
     *
     * @param {Element} origin The control the user interacted with
     *
     * @return {void}
     */
    function clearError(origin) {
        var target = origin && origin.closest ? origin.closest('div, form, td') : null;
        var element = (target || document).querySelector('[data-passkey-error]');

        if (element) {
            element.hidden = true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the login button
     *
     * @return {void}
     */
    function bindLoginButtons() {
        var buttons = document.querySelectorAll('[data-passkey-login]');

        Array.prototype.forEach.call(buttons, function(button) {

            /**
             * The button may be wrapped in a block carrying a separator; reveal the two
             * together so a rule never appears without the button it introduces.
             */
            var block = button.closest ? button.closest('[data-passkey-block]') : null;

            if (block) {
                block.hidden = false;
            }

            button.hidden = false;

            button.addEventListener('click', function() {

                clearError(button);
                abortConditional();

                setBusy(button, true);

                var form = document.getElementById('login-form');
                var remember = form ? form.querySelector('[name="remember"]') : null;

                login({
                    'remember': remember ? remember.checked : false,
                    'returnTo': button.getAttribute('data-passkey-return-to') ||
                        (form ? form.getAttribute('data-passkey-return-to') : null)
                }).catch(function(error) {
                    setBusy(button, false);
                    if (!isCancellation(error)) {
                        showError(button, describeError(error));
                    }
                });
            });
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Starts a conditional request so the identifier field can offer a passkey
     *
     * @return {void}
     */
    function bindConditional() {
        var field = document.querySelector('[data-passkey-conditional]');

        if (!field) {
            return;
        }

        isConditionalMediationAvailable().then(function(available) {

            if (!available) {
                return;
            }

            var form = document.getElementById('login-form');

            login({
                'conditional': true,
                'returnTo': form ? form.getAttribute('data-passkey-return-to') : null
            }).catch(function() {
                //  A cancelled or superseded conditional request is not worth reporting
            });
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the "add a passkey" buttons
     *
     * @return {void}
     */
    function bindRegisterButtons() {
        var buttons = document.querySelectorAll('[data-passkey-register]');

        if (!buttons.length) {
            return;
        }

        Array.prototype.forEach.call(buttons, function(button) {

            var isNudge = button.hasAttribute('data-passkey-nudge');

            /**
             * On the nudge, a device with nothing to enrol should not be asked at all,
             * so the browser answers on the user's behalf and moves them along.
             */
            var ready = isNudge ? isPlatformAuthenticatorAvailable() : Promise.resolve(isSupported());

            ready.then(function(available) {

                if (!available) {
                    if (isNudge) {
                        var skip = document.getElementById('passkey-nudge-skip');
                        if (skip) {
                            skip.submit();
                        }
                    } else {
                        var notice = document.querySelector('[data-passkey-unsupported]');
                        if (notice) {
                            notice.hidden = false;
                        }
                    }
                    return;
                }

                button.hidden = false;

                button.addEventListener('click', function() {

                    clearError(button);
                    setBusy(button, true);

                    var labelField = document.querySelector('[data-passkey-label]');

                    register({'label': labelField ? labelField.value : null})
                        .then(function() {
                            window.location.assign(
                                button.getAttribute('data-passkey-redirect') || window.location.href
                            );
                        })
                        .catch(function(error) {
                            setBusy(button, false);
                            if (!isCancellation(error)) {
                                showError(button, describeError(error));
                            }
                        });
                });
            });
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the declarative controls the MFA driver renders
     *
     * The driver supplies options and a target; the ceremony runs here, the result is
     * written into a hidden input, and the surrounding form is submitted. That keeps
     * the driver free of JavaScript of its own.
     *
     * @return {void}
     */
    function bindDriverControls() {

        var controls = document.querySelectorAll('[data-passkey-assert][data-options], [data-passkey-create][data-options]');

        Array.prototype.forEach.call(controls, function(control) {

            if (!isSupported()) {
                showError(control, 'This browser does not support passkeys.');
                return;
            }

            control.hidden = false;

            control.addEventListener('click', function() {

                clearError(control);
                control.disabled = true;

                var options;

                try {
                    options = JSON.parse(control.getAttribute('data-options'));
                } catch (e) {
                    control.disabled = false;
                    showError(control, 'This request is malformed; please reload the page.');
                    return;
                }

                var ceremony = control.hasAttribute('data-passkey-create')
                    ? create(options)
                    : get(options, {});

                ceremony
                    .then(function(credential) {

                        var target = document.querySelector(control.getAttribute('data-target'));
                        var form = control.closest('form');

                        if (!target || !form) {
                            throw new Error('unexpected');
                        }

                        target.value = JSON.stringify(credential);

                        var action = control.getAttribute('data-action');
                        if (action) {
                            var field = form.querySelector('[name="action"]');
                            if (field) {
                                field.value = action;
                            }
                        }

                        form.submit();
                    })
                    .catch(function(error) {
                        control.disabled = false;
                        if (!isCancellation(error)) {
                            showError(control, describeError(error));
                        }
                    });
            });
        });
    }

    // --------------------------------------------------------------------------

    window.NAILS = window.NAILS || {};
    window.NAILS.PASSKEY = {
        'isSupported': isSupported,
        'isConditionalMediationAvailable': isConditionalMediationAvailable,
        'isPlatformAuthenticatorAvailable': isPlatformAuthenticatorAvailable,
        'toJSON': toJSON,
        'decodeCreationOptions': decodeCreationOptions,
        'decodeRequestOptions': decodeRequestOptions,
        'create': create,
        'get': get,
        'register': register,
        'login': login,
        'abortConditional': abortConditional
    };

    // --------------------------------------------------------------------------

    /**
     * Wires up every declarative control on the page
     *
     * @return {void}
     */
    function boot() {

        if (!isSupported()) {
            return;
        }

        bindLoginButtons();
        bindConditional();
        bindRegisterButtons();
        bindDriverControls();
    }

    // --------------------------------------------------------------------------

    /**
     * DOMContentLoaded may already have fired by the time this runs - the asset is
     * deferred, and a page may inject it later still - in which case waiting for the
     * event would leave every control hidden.
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

})();
