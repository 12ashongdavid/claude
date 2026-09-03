// Live email-provider check. Include this file once in the shared layout
// and it takes care of every email input on the page by itself — no need
// to wire it up per form.
(function () {
    'use strict';

    // Keep this list in sync with getAcceptedEmailDomains() /
    // getAcceptedEmailDomainSuffixes() in config/database.php. The client
    // side check is just a fast first opinion — the server always has the
    // final say.
    var ACCEPTED_DOMAINS = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'aol.com', 'protonmail.com'];
    var ACCEPTED_SUFFIXES = ['.edu.gh', '.ac.gh', '.gov.gh'];

    function isAcceptedDomain(domain) {
        domain = domain.toLowerCase();
        if (ACCEPTED_DOMAINS.indexOf(domain) !== -1) {
            return true;
        }
        for (var i = 0; i < ACCEPTED_SUFFIXES.length; i++) {
            if (domain.slice(-ACCEPTED_SUFFIXES[i].length) === ACCEPTED_SUFFIXES[i]) {
                return true;
            }
        }
        return false;
    }

    // Don't judge a domain while it's still being typed — "gm" isn't wrong,
    // it's just not finished yet. Wait for a dot with a couple of
    // characters after it before forming an opinion.
    function domainLooksFinished(domain) {
        var dot = domain.lastIndexOf('.');
        return dot > 0 && (domain.length - dot - 1) >= 2;
    }

    function feedbackElementFor(input) {
        if (input._emailFeedbackEl) {
            return input._emailFeedbackEl;
        }
        var el = document.createElement('div');
        el.className = 'email-provider-feedback';
        el.style.fontSize = '0.8rem';
        el.style.marginTop = '4px';
        el.style.lineHeight = '1.4';
        el.style.display = 'none';
        input.insertAdjacentElement('afterend', el);
        input._emailFeedbackEl = el;
        return el;
    }

    function setState(input, ok, message) {
        var el = feedbackElementFor(input);
        el.textContent = message;
        el.style.display = message ? 'block' : 'none';
        el.style.color = ok ? 'var(--success)' : 'var(--danger)';
        input.style.borderColor = message ? (ok ? 'var(--success)' : 'var(--danger)') : '';
    }

    function checkEmail(input, isFinalCheck) {
        var value = input.value.trim();
        var atIndex = value.indexOf('@');
        if (!value || atIndex === -1) {
            setState(input, true, '');
            return;
        }
        var domain = value.slice(atIndex + 1);
        if (!isFinalCheck && !domainLooksFinished(domain)) {
            setState(input, true, '');
            return;
        }
        if (isAcceptedDomain(domain)) {
            setState(input, true, "Looks good — that's a supported email provider.");
        } else {
            setState(input, false, 'Please use an accepted email provider (Gmail, Yahoo, Outlook, Hotmail, iCloud, AOL, ProtonMail, or a Ghanaian school/government address).');
        }
    }

    function attach(input) {
        if (input._emailValidatorAttached) {
            return;
        }
        input._emailValidatorAttached = true;
        input.addEventListener('input', function () { checkEmail(input, false); });
        input.addEventListener('blur', function () { checkEmail(input, true); });
    }

    function scanForEmailInputs() {
        var inputs = document.querySelectorAll('input[type="email"]');
        for (var i = 0; i < inputs.length; i++) {
            attach(inputs[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scanForEmailInputs);
    } else {
        scanForEmailInputs();
    }

    // Some email fields live inside modals that get added to the page
    // after this script first runs, so keep watching for new ones.
    new MutationObserver(scanForEmailInputs).observe(document.body, { childList: true, subtree: true });
})();
