/**
 * WebNique SEO Spider — Admin JS
 * Handles: progressive crawl polling, PSI analysis, intent classification
 */
(function ($) {
    'use strict';

    let spiderSessionId  = null;
    let spiderPollTimer  = null;
    let spiderRunning    = false;

    // ── Start Crawl ──────────────────────────────────────────────────────
    window.wnqSpiderStart = function (clientId) {
        const url      = $('#spider-start-url').val().trim();
        const maxDepth = $('#spider-max-depth').val();

        if (!url) {
            alert('Please enter a start URL.');
            return;
        }

        $('#spider-start-btn').prop('disabled', true).text('Starting…');
        $('#spider-progress').show();
        $('#spider-status-text').text('Initializing crawl…');
        $('#spider-bar').css('width', '5%');
        $('#spider-counts').text('');

        $.post(WNQ_SPIDER.ajaxUrl, {
            action:       'wnq_spider',
            nonce:        WNQ_SPIDER.nonce,
            spider_action:'start_crawl',
            client_id:    clientId,
            start_url:    url,
            max_depth:    maxDepth,
        }, function (res) {
            if (res.success) {
                spiderSessionId = res.data.session_id;
                spiderRunning   = true;
                wnqSpiderPoll(clientId);
            } else {
                wnqSpiderError(res.data.message || 'Failed to start crawl.');
            }
        }).fail(function () {
            wnqSpiderError('AJAX error starting crawl.');
        });
    };

    // ── Poll Progress ─────────────────────────────────────────────────────
    function wnqSpiderPoll(clientId) {
        if (!spiderRunning || !spiderSessionId) return;

        $.post(WNQ_SPIDER.ajaxUrl, {
            action:        'wnq_spider',
            nonce:         WNQ_SPIDER.nonce,
            spider_action: 'crawl_batch',
            client_id:     clientId,
            session_id:    spiderSessionId,
        }, function (res) {
            if (!res.success) {
                wnqSpiderError(res.data.message || 'Crawl batch error.');
                return;
            }

            const d = res.data;
            const crawled = parseInt(d.crawled) || 0;
            const queued  = parseInt(d.queued)  || 0;
            const issues  = parseInt(d.issues)  || 0;
            const total   = crawled + queued;
            const pct     = total > 0 ? Math.max(5, Math.min(98, Math.round(crawled / total * 100))) : 5;

            $('#spider-bar').css('width', pct + '%');
            $('#spider-pct').text(crawled + ' crawled');
            $('#spider-counts').text(
                'Queued: ' + queued + ' · Issues found: ' + issues
            );

            if (d.done) {
                spiderRunning = false;
                $('#spider-bar').css('width', '100%');
                $('#spider-status-text').html('✅ Crawl complete! <a href="?page=wnq-seo-spider&tab=spider&client_id=' + clientId + '&session_id=' + spiderSessionId + '" style="color:#0d539e;font-weight:700;">View Results →</a>');
                $('#spider-start-btn').prop('disabled', false).text('▶ Start Crawl');
                $('#spider-pct').text(crawled + ' pages crawled');
            } else {
                $('#spider-status-text').text('Crawling… ' + pct + '%');
                spiderPollTimer = setTimeout(function () { wnqSpiderPoll(clientId); }, 1500);
            }
        }).fail(function () {
            // Retry on network hiccup
            spiderPollTimer = setTimeout(function () { wnqSpiderPoll(clientId); }, 3000);
        });
    }

    // ── Delete Session ────────────────────────────────────────────────────
    window.wnqSpiderDeleteSession = function (sessionId, clientId) {
        if (!confirm('Delete this crawl session and all its data?')) return;

        $.post(WNQ_SPIDER.ajaxUrl, {
            action:        'wnq_spider',
            nonce:         WNQ_SPIDER.nonce,
            spider_action: 'delete_session',
            client_id:     clientId,
            session_id:    sessionId,
        }, function (res) {
            if (res.success) {
                location.reload();
            }
        });
    };

    // ── Page Speed Analysis ───────────────────────────────────────────────
    window.wnqSpiderPSI = function (clientId) {
        const $btn    = $('button[onclick*="wnqSpiderPSI"]');
        const $result = $('#spider-action-result');

        $btn.prop('disabled', true).text('Analyzing…');
        $result.removeClass('success error').text('Running PageSpeed Insights — this may take 30–60 seconds…').css('padding', '10px 14px').css('background', '#fffbeb').css('border', '1px solid #fde68a').css('border-radius', '6px');

        $.post(WNQ_SPIDER.ajaxUrl, {
            action:        'wnq_spider',
            nonce:         WNQ_SPIDER.nonce,
            spider_action: 'analyze_psi',
            client_id:     clientId,
        }, function (res) {
            $btn.prop('disabled', false).text('⚡ Analyze Key Pages');
            if (res.success) {
                $result.addClass('success').text('✅ ' + res.data.message).css('background', '').css('border', '').css('border-radius', '');
            } else {
                $result.addClass('error').text('❌ ' + (res.data.message || 'PSI analysis failed.')).css('background', '').css('border', '').css('border-radius', '');
            }
        }, 'json').fail(function () {
            $btn.prop('disabled', false).text('⚡ Analyze Key Pages');
            $result.addClass('error').text('❌ Request failed. Check your API key and try again.').css('background', '').css('border', '').css('border-radius', '');
        });
    };

    // ── Keyword Intent Classification ─────────────────────────────────────
    window.wnqSpiderClassifyIntent = function (clientId) {
        const $btn    = $('button[onclick*="wnqSpiderClassifyIntent"]');
        const $result = $('#spider-action-result');

        $btn.prop('disabled', true).text('Classifying…');
        $result.removeClass('success error').text('Classifying keyword intent…').css('padding', '10px 14px').css('background', '#fffbeb').css('border', '1px solid #fde68a').css('border-radius', '6px');

        $.post(WNQ_SPIDER.ajaxUrl, {
            action:        'wnq_spider',
            nonce:         WNQ_SPIDER.nonce,
            spider_action: 'classify_intent',
            client_id:     clientId,
        }, function (res) {
            $btn.prop('disabled', false).text('🏷️ Classify Keyword Intent');
            if (res.success) {
                $result.addClass('success').text('✅ ' + res.data.message).css('background', '').css('border', '').css('border-radius', '');
            } else {
                $result.addClass('error').text('❌ ' + (res.data.message || 'Failed.')).css('background', '').css('border', '').css('border-radius', '');
            }
        }, 'json');
    };

    // ── Error helper ──────────────────────────────────────────────────────
    function wnqSpiderError(msg) {
        spiderRunning = false;
        $('#spider-bar').css('width', '0%');
        $('#spider-status-text').text('❌ ' + msg);
        $('#spider-start-btn').prop('disabled', false).text('▶ Start Crawl');
    }

    // ── Cleanup on page unload ────────────────────────────────────────────
    $(window).on('beforeunload', function () {
        if (spiderPollTimer) clearTimeout(spiderPollTimer);
    });

})(jQuery);
