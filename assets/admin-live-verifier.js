(function () {
    'use strict';

    var config = window.BlogLogisticsMFALiveVerifier || null;

    if (!config || !config.ajaxUrl || !config.nonce || !config.autoStart) {
        return;
    }

    var concurrency = 4;
    var progressNotice = null;
    var maxResponseBytes = Number(config.maxResponseBytes) > 0 ? Number(config.maxResponseBytes) : 1048576;

    function label(name, fallback) {
        return config.labels && config.labels[name] ? config.labels[name] : fallback;
    }

    function showNotice(message, type) {
        if (!progressNotice) {
            progressNotice = document.createElement('div');
            progressNotice.id = 'bl-mfa-browser-verification-progress';
            var heading = document.querySelector('.bl-mfa-wrap > h1');
            if (heading && heading.parentNode) {
                heading.parentNode.insertBefore(progressNotice, heading.nextSibling);
            } else {
                document.body.insertBefore(progressNotice, document.body.firstChild);
            }
        }

        progressNotice.className = 'notice notice-' + (type || 'info') + ' inline';
        progressNotice.innerHTML = '';
        var paragraph = document.createElement('p');
        paragraph.textContent = message;
        progressNotice.appendChild(paragraph);
    }

    function ajaxPost(values) {
        var body = new URLSearchParams();
        Object.keys(values).forEach(function (key) {
            body.append(key, values[key]);
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function cacheBustUrl(url) {
        var parsed = new URL(url, window.location.href);
        parsed.searchParams.set('bloglogistics_mfa_browser_verify', Date.now().toString(36) + Math.random().toString(36).slice(2));
        return parsed.toString();
    }

    function responseTooLargeError() {
        var error = new Error('Response exceeded the browser verification size limit');
        error.name = 'ResponseTooLargeError';
        return error;
    }

    function readTextWithLimit(response) {
        var declaredLength = parseInt(response.headers.get('content-length') || '0', 10);
        if (declaredLength > maxResponseBytes) {
            if (response.body && typeof response.body.cancel === 'function') {
                response.body.cancel().catch(function () {});
            }
            return Promise.reject(responseTooLargeError());
        }

        if (!response.body || typeof response.body.getReader !== 'function' || typeof TextDecoder === 'undefined') {
            return response.text().then(function (text) {
                var byteLength = typeof TextEncoder !== 'undefined'
                    ? new TextEncoder().encode(text).byteLength
                    : text.length;
                if (byteLength > maxResponseBytes) {
                    throw responseTooLargeError();
                }
                return text;
            });
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var received = 0;
        var text = '';

        function readNext() {
            return reader.read().then(function (result) {
                if (result.done) {
                    text += decoder.decode();
                    return text;
                }

                received += result.value.byteLength;
                if (received > maxResponseBytes) {
                    reader.cancel().catch(function () {});
                    throw responseTooLargeError();
                }

                text += decoder.decode(result.value, {stream: true});
                return readNext();
            });
        }

        return readNext();
    }

    function fetchPublic(url, kind, retry) {
        var requestedUrl = retry ? cacheBustUrl(url) : url;
        var accept = kind === 'html'
            ? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'
            : 'text/markdown,text/plain;q=0.9,*/*;q=0.8';

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = controller ? window.setTimeout(function () { controller.abort(); }, 15000) : null;

        return fetch(requestedUrl, {
            method: 'GET',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'follow',
            signal: controller ? controller.signal : undefined,
            headers: {
                'Accept': accept,
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        }).then(function (response) {
            var base = {
                status: response.status,
                contentType: response.headers.get('content-type') || '',
                redirected: !!response.redirected,
                finalUrl: response.url || requestedUrl,
                body: '',
                error: ''
            };

            if (kind !== 'html') {
                if (response.body && typeof response.body.cancel === 'function') {
                    return response.body.cancel().catch(function () {}).then(function () { return base; });
                }
                return base;
            }

            return readTextWithLimit(response).then(function (text) {
                base.body = text || '';
                return base;
            }).catch(function (error) {
                base.error = error && error.name === 'ResponseTooLargeError'
                    ? 'Browser response exceeded the verification size limit'
                    : (error && error.message ? String(error.message) : 'Browser response could not be read');
                return base;
            });
        }).catch(function (error) {
            return {
                status: 0,
                contentType: '',
                redirected: false,
                finalUrl: requestedUrl,
                body: '',
                error: error && error.name === 'AbortError'
                    ? 'Browser request timed out'
                    : (error && error.message ? String(error.message) : 'Browser request failed')
            };
        }).finally(function () {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }
        });
    }

    function mimeState(contentType) {
        var base = String(contentType || '').split(';', 1)[0].trim().toLowerCase();
        if (!base) {
            return 'missing';
        }
        if (base === 'text/markdown') {
            return 'preferred';
        }
        if (base === 'text/plain' || base === 'text/x-markdown' || base === 'application/markdown') {
            return 'warning';
        }
        return 'bad';
    }

    function normalizedUrl(raw, base) {
        try {
            var value = new URL(raw, base);
            value.hash = '';
            var path = value.pathname.replace(/\/+$/, '');
            if (!path) {
                path = '/';
            }
            return value.protocol.toLowerCase() + '//' + value.host.toLowerCase() + path + value.search;
        } catch (e) {
            return '';
        }
    }

    function discoveryFromHtml(html, target) {
        var markdownFound = false;
        var llmsFound = false;

        try {
            var documentObject = new DOMParser().parseFromString(html || '', 'text/html');
            var links = documentObject.querySelectorAll('link[href][rel]');
            var expectedMarkdown = normalizedUrl(target.markdownUrl, target.htmlUrl);
            var expectedLlms = normalizedUrl(target.llmsUrl, target.htmlUrl);

            links.forEach(function (link) {
                var rel = String(link.getAttribute('rel') || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
                var href = normalizedUrl(link.getAttribute('href') || '', target.htmlUrl);
                var type = String(link.getAttribute('type') || '').split(';', 1)[0].trim().toLowerCase();

                if (rel.indexOf('alternate') !== -1 && type === 'text/markdown' && href && href === expectedMarkdown) {
                    markdownFound = true;
                }

                if (rel.indexOf('describedby') !== -1 && href && href === expectedLlms) {
                    llmsFound = true;
                }
            });
        } catch (e) {
            // Parsing failure is represented by both discovery booleans false.
        }

        return {
            markdownFound: markdownFound,
            llmsFound: llmsFound
        };
    }

    function discoveryMatches(discovery, target) {
        var markdownOk = target.expectMarkdown ? discovery.markdownFound : !discovery.markdownFound;
        var llmsOk = target.expectLlms ? discovery.llmsFound : !discovery.llmsFound;
        return markdownOk && llmsOk;
    }

    function chooseRetry(initial, retry, kind) {
        if (!retry) {
            return initial;
        }

        if (!retry.error && retry.status === 200) {
            if (kind !== 'markdown' || mimeState(retry.contentType) === 'preferred') {
                return retry;
            }
            // A successful fresh HTTP response still beats an initial failure.
            if (initial.error || initial.status !== 200 || mimeState(retry.contentType) !== 'bad') {
                return retry;
            }
        }

        if (initial.error || initial.status === 0) {
            return retry;
        }

        if (initial.status !== 200 && retry.status !== 0) {
            return retry;
        }

        return initial;
    }

    async function verifyTarget(target) {
        var htmlInitial = await fetchPublic(target.htmlUrl, 'html', false);
        var htmlRetry = null;
        var htmlEffective = htmlInitial;
        var retryUsed = false;

        if (htmlInitial.error || htmlInitial.status !== 200) {
            htmlRetry = await fetchPublic(target.htmlUrl, 'html', true);
            htmlEffective = chooseRetry(htmlInitial, htmlRetry, 'html');
            retryUsed = true;
        }

        var markdownInitial = await fetchPublic(target.markdownUrl, 'markdown', false);
        var markdownRetry = null;
        var markdownEffective = markdownInitial;

        if (markdownInitial.error || markdownInitial.status !== 200 || mimeState(markdownInitial.contentType) !== 'preferred') {
            markdownRetry = await fetchPublic(target.markdownUrl, 'markdown', true);
            markdownEffective = chooseRetry(markdownInitial, markdownRetry, 'markdown');
            retryUsed = true;
        }

        var discovery = {
            markdownFound: false,
            llmsFound: false
        };

        if (!htmlEffective.error && htmlEffective.status === 200) {
            discovery = discoveryFromHtml(htmlEffective.body, target);

            if (!discoveryMatches(discovery, target)) {
                if (!htmlRetry) {
                    htmlRetry = await fetchPublic(target.htmlUrl, 'html', true);
                    retryUsed = true;
                }

                if (htmlRetry && !htmlRetry.error && htmlRetry.status === 200) {
                    var freshDiscovery = discoveryFromHtml(htmlRetry.body, target);
                    // A successful fresh discovery result wins outright. If the
                    // fresh result still differs, use it as the current result.
                    htmlEffective = htmlRetry;
                    discovery = freshDiscovery;
                }
            }
        }

        return {
            post_id: target.postId,
            html_initial_status: htmlInitial.status || 0,
            html_status: htmlEffective.status || 0,
            html_redirected: !!htmlInitial.redirected,
            html_error: htmlEffective.error || '',
            html_recheck_status: htmlRetry ? (htmlRetry.status || 0) : 0,
            markdown_initial_status: markdownInitial.status || 0,
            markdown_status: markdownEffective.status || 0,
            markdown_redirected: !!markdownInitial.redirected,
            markdown_error: markdownEffective.error || '',
            markdown_recheck_status: markdownRetry ? (markdownRetry.status || 0) : 0,
            content_type: markdownEffective.contentType || '',
            markdown_discovery_found: !!discovery.markdownFound,
            llms_discovery_found: !!discovery.llmsFound,
            retry_used: retryUsed
        };
    }

    async function runPool(targets, onProgress) {
        var results = new Array(targets.length);
        var nextIndex = 0;
        var completed = 0;

        async function worker() {
            while (true) {
                var index = nextIndex++;
                if (index >= targets.length) {
                    return;
                }
                results[index] = await verifyTarget(targets[index]);
                completed++;
                onProgress(completed, targets.length);
            }
        }

        var workers = [];
        var count = Math.min(concurrency, targets.length);
        for (var i = 0; i < count; i++) {
            workers.push(worker());
        }
        await Promise.all(workers);
        return results;
    }

    async function start() {
        showNotice(label('starting', 'Browser verification is starting...'), 'info');

        var targetResponse = await ajaxPost({
            action: 'bloglogistics_mfa_browser_verify_targets',
            nonce: config.nonce
        });

        if (!targetResponse || !targetResponse.success) {
            var targetMessage = targetResponse && targetResponse.data && targetResponse.data.message
                ? targetResponse.data.message
                : label('error', 'Browser verification could not be completed.');
            showNotice(targetMessage, 'error');
            return;
        }

        var targets = targetResponse.data && Array.isArray(targetResponse.data.targets)
            ? targetResponse.data.targets
            : [];

        if (!targets.length) {
            showNotice(label('noTargets', 'No Markdown files are available for live verification.'), 'warning');
            return;
        }

        var results = await runPool(targets, function (done, total) {
            var template = label('progress', 'Browser verification: %1$d of %2$d checked.');
            var message = template.replace('%1$d', String(done)).replace('%2$d', String(total));
            showNotice(message, 'info');
        });

        showNotice(label('saving', 'Browser verification finished. Saving results...'), 'info');

        var saveResponse = await ajaxPost({
            action: 'bloglogistics_mfa_store_browser_results',
            nonce: config.nonce,
            results: JSON.stringify(results)
        });

        if (!saveResponse || !saveResponse.success || !saveResponse.data || !saveResponse.data.redirectUrl) {
            var saveMessage = saveResponse && saveResponse.data && saveResponse.data.message
                ? saveResponse.data.message
                : label('error', 'Browser verification could not be completed.');
            showNotice(saveMessage, 'error');
            return;
        }

        window.location.assign(saveResponse.data.redirectUrl);
    }

    document.addEventListener('DOMContentLoaded', function () {
        start().catch(function (error) {
            var message = error && error.message ? error.message : label('error', 'Browser verification could not be completed.');
            showNotice(message, 'error');
        });
    });
}());
