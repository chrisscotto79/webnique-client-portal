/**
 * WebNique SEO OS — Admin JavaScript
 * Handles AJAX calls, tabs, and SEO OS admin interactions.
 */

(function ($) {
  'use strict';

  // ── AJAX Wrapper ─────────────────────────────────────────────────────────

  window.wnqHubAjax = function (action, clientId, entityId) {
    const $result = $('#wnq-action-result');
    $result.removeClass('success error').text('').hide();

    const $btn = $('[onclick*="' + action + '"]').first();
    const origText = $btn.html();
    $btn.html('⏳ Working...').prop('disabled', true);

    $.post(WNQ_SEOHUB.ajaxUrl, {
      action:     'wnq_seohub_action',
      hub_action: action,
      client_id:  clientId || '',
      entity_id:  entityId || 0,
      nonce:      WNQ_SEOHUB.nonce
    })
    .done(function (res) {
      if (res.success) {
        showResult('success', '✅ ' + res.data.message);
      } else {
        showResult('error', '❌ ' + (res.data?.message || 'An error occurred'));
      }
    })
    .fail(function () {
      showResult('error', '❌ Network error — check your connection');
    })
    .always(function () {
      $btn.html(origText).prop('disabled', false);
    });

    function showResult(type, msg) {
      $result.addClass(type).html(msg).show();
      setTimeout(() => $result.fadeOut(400, () => $result.removeClass('success error').text('')), 6000);
    }
  };

  // ── Auto-Fix SEO: Pulsing Progress Bar ───────────────────────────────────

  window.wnqAutoFixSEO = function (clientId) {
    var $btn      = $('#wnq-fix-btn');
    var $progress = $('#wnq-fix-progress');
    var $bar      = $('#wnq-fix-bar');
    var $pct      = $('#wnq-fix-pct');
    var $status   = $('#wnq-fix-status');
    var $counts   = $('#wnq-fix-counts');
    var $result   = $('#wnq-action-result');

    var totalPages   = 0;
    var fixedTotal   = 0;
    var failedTotal  = 0;
    var skippedTotal = 0;
    var batchNum     = 0;

    $btn.prop('disabled', true).html('⏳ Fixing…');
    $result.hide().removeClass('success error');
    $progress.show();
    $bar.css('width', '0%');
    $pct.text('0%');
    $status.text('Starting…');
    $counts.text('');

    // Step 1: get total fixable page count so the bar starts at the right scale
    $.post(WNQ_SEOHUB.ajaxUrl, {
      action:     'wnq_seohub_action',
      hub_action: 'fix_seo_count',
      client_id:  clientId,
      nonce:      WNQ_SEOHUB.nonce
    }).done(function (res) {
      totalPages = (res.success && res.data && res.data.total) ? res.data.total : 0;
      if (totalPages === 0) {
        finish('No fixable SEO issues found — site is already clean!', true);
        return;
      }
      $status.text('Found ' + totalPages + ' pages to fix. Processing…');
      runNextBatch();
    }).fail(function () {
      // Can't get count — run anyway
      runNextBatch();
    });

    function runNextBatch() {
      batchNum++;
      $status.text('Batch ' + batchNum + ': sending fixes to your site…');

      $.post(WNQ_SEOHUB.ajaxUrl, {
        action:     'wnq_seohub_action',
        hub_action: 'fix_seo_batch',
        client_id:  clientId,
        nonce:      WNQ_SEOHUB.nonce
      })
      .done(function (res) {
        if (!res.success) {
          finish('Error: ' + (res.data ? res.data.message || JSON.stringify(res.data) : 'Unknown error'), false);
          return;
        }

        var d = res.data;
        fixedTotal   += (d.fixed   || 0);
        failedTotal  += (d.failed  || 0);
        skippedTotal += (d.skipped || 0);

        var processed = fixedTotal + failedTotal + skippedTotal;

        // Update progress bar
        if (totalPages > 0) {
          var pct = Math.min(100, Math.round((processed / totalPages) * 100));
          $bar.css('width', pct + '%');
          $pct.text(pct + '%');
        }

        $counts.text(
          '✅ Fixed: ' + fixedTotal +
          '  ❌ Failed: ' + failedTotal +
          '  ⏭ Skipped: ' + skippedTotal +
          '  ⏳ Remaining: ' + (d.remaining || 0)
        );

        if (d.done || (d.remaining === 0)) {
          finish('Auto-fix complete! Fixed ' + fixedTotal + ' page' + (fixedTotal !== 1 ? 's' : '') + '.', true);
        } else {
          // Pulse — wait 800ms then run next batch
          setTimeout(runNextBatch, 800);
        }
      })
      .fail(function () {
        finish('Network error during batch ' + batchNum + '. Check your connection.', false);
      });
    }

    function finish(msg, success) {
      $bar.css('width', '100%');
      $pct.text('100%');
      $status.html(success ? '✅ <strong>' + msg + '</strong>' : '❌ ' + msg);
      $btn.prop('disabled', false).html('🔧 Auto-Fix SEO Issues');

      // Also flash the standard result area
      var $r = $('#wnq-action-result');
      $r.removeClass('success error').addClass(success ? 'success' : 'error')
        .html((success ? '✅ ' : '❌ ') + msg).show();

      if (success && fixedTotal > 0) {
        // Reload the page so the Open Findings count, severity bars, and
        // findings table all reflect the newly-resolved items.
        $status.html('✅ <strong>' + msg + '</strong> Refreshing…');
        setTimeout(function () { location.reload(); }, 2000);
      } else {
        setTimeout(function () { $r.fadeOut(400, function () { $r.removeClass('success error').text(''); }); }, 8000);
      }
    }
  };

  // ── Resolve / Verify Finding (findings table row-level actions) ──────────

  window.wnqResolveFinding = function (findingId, btn) {
    var $btn = $(btn);
    $btn.prop('disabled', true).html('⏳…');

    $.post(WNQ_SEOHUB.ajaxUrl, {
      action:     'wnq_seohub_action',
      hub_action: 'resolve_finding',
      entity_id:  findingId,
      nonce:      WNQ_SEOHUB.nonce
    })
    .done(function (res) {
      if (res.success) {
        $btn.closest('tr').css({ background: '#f0fdf4', opacity: '0.5' });
        $btn.closest('td').html('<span style="color:#16a34a;font-weight:600;font-size:12px;">✓ Resolved</span>');
        setTimeout(function () { $btn.closest('tr').fadeOut(400); }, 1200);
      } else {
        $btn.prop('disabled', false).html('✓ Resolve');
        alert('Error: ' + (res.data ? res.data.message : 'Unknown error'));
      }
    })
    .fail(function () {
      $btn.prop('disabled', false).html('✓ Resolve');
      alert('Network error — try again.');
    });
  };

  window.wnqVerifyFinding = function (findingId, btn) {
    var $btn = $(btn);
    $btn.prop('disabled', true).html('⏳ Checking…');

    $.post(WNQ_SEOHUB.ajaxUrl, {
      action:     'wnq_seohub_action',
      hub_action: 'verify_finding',
      entity_id:  findingId,
      nonce:      WNQ_SEOHUB.nonce
    })
    .done(function (res) {
      var msg     = res.data ? res.data.message : 'Unknown response';
      var resolved = res.data && res.data.resolved;

      if (resolved) {
        // Visually retire the row
        $btn.closest('tr').css({ background: '#f0fdf4', opacity: '0.5' });
        $btn.closest('td').html('<span style="color:#16a34a;font-weight:600;font-size:12px;">✅ Verified & Resolved</span>');
        setTimeout(function () { $btn.closest('tr').fadeOut(400); }, 1500);
      } else {
        // Show inline warning on the button
        $btn.prop('disabled', false)
            .html('⚠️ Not yet confirmed')
            .css({ background: '#fffbeb', color: '#b45309', borderColor: '#fcd34d' });
        // Add a tooltip-like note below the button
        var $note = $btn.next('.wnq-verify-note');
        if (!$note.length) {
          $note = $('<div class="wnq-verify-note" style="font-size:11px;color:#6b7280;margin-top:4px;max-width:200px;line-height:1.3;"></div>');
          $btn.after($note);
        }
        $note.text('Run a fresh audit after your site syncs to confirm.');
      }
    })
    .fail(function () {
      $btn.prop('disabled', false).html('🔍 Verify Fix');
      alert('Network error — try again.');
    });
  };

  // ── Tab Switching ─────────────────────────────────────────────────────────

  $(document).on('click', '.wnq-tab', function (e) {
    e.preventDefault();
    const target = $(this).attr('href');
    if (!target || target.charAt(0) !== '#') return;

    const $container = $(this).closest('.wnq-hub-client-profile, .wnq-hub-section');
    $container.find('.wnq-tab').removeClass('active');
    $container.find('.wnq-tab-panel').hide().removeClass('active');
    $(this).addClass('active');
    $container.find(target).show().addClass('active');
  });

  // ── Form: Save AI Settings (live validation) ──────────────────────────────

  $(document).on('change', 'select[name="provider"]', function () {
    const provider = $(this).val();
    const hints = {
      groq:    'Free tier at console.groq.com — up to 14,400 requests/day',
      openai:  'Paid API — requires billing at platform.openai.com',
      together:'Free $25 credit at api.together.xyz',
    };
    let $hint = $(this).siblings('.provider-hint');
    if (!$hint.length) {
      $hint = $('<p class="provider-hint description" style="margin-top:4px;">').insertAfter($(this));
    }
    $hint.text(hints[provider] || '');
  });

  // ── Init ──────────────────────────────────────────────────────────────────

  $(document).ready(function () {
    // Auto-activate first tab
    $('.wnq-hub-tabs .wnq-tab:first-child').addClass('active');
    $('.wnq-tab-panel:first-child').addClass('active').show();

    // Number formatting for stats
    $('.wnq-hub-stat .value').each(function () {
      const val = parseInt($(this).text(), 10);
      if (!isNaN(val) && val >= 1000) {
        $(this).text(val.toLocaleString());
      }
    });
  });

})(jQuery);
