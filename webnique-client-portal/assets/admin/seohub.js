/**
 * WebNique SEO OS — Admin JavaScript
 * Handles AJAX calls, tabs, modals, and job viewing
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
      setTimeout(function () { $r.fadeOut(400, function () { $r.removeClass('success error').text(''); }); }, 8000);
    }
  };

  // ── View/Approve Job Modal ────────────────────────────────────────────────

  window.wnqViewJob = function (jobId, btn) {
    const $overlay = $('<div class="wnq-modal-overlay" id="wnq-job-modal">');
    const $modal   = $('<div class="wnq-modal">');
    const $header  = $('<div class="wnq-modal-header"><h3>AI Generated Content</h3><button id="wnq-close-modal" style="background:none;border:none;font-size:20px;cursor:pointer;">✕</button></div>');
    const $body    = $('<div class="wnq-modal-body"><div style="text-align:center;padding:40px;color:#6b7280;">Loading content...</div></div>');
    const $footer  = $('<div class="wnq-modal-footer"></div>');

    $overlay.append($modal.append($header).append($body).append($footer));
    $('body').append($overlay);

    // Load job content
    $.post(WNQ_SEOHUB.ajaxUrl, {
      action:     'wnq_seohub_get_job',
      job_id:     jobId,
      nonce:      WNQ_SEOHUB.nonce
    }).done(function (res) {
      if (res.success) {
        const job = res.data;
        $body.html(
          '<p style="margin-bottom:12px;"><strong>Type:</strong> ' + job.job_type + ' &nbsp;|&nbsp; <strong>Keyword:</strong> ' + (job.target_keyword || '—') + '</p>' +
          '<div class="wnq-modal-content">' + wnqEscape(job.output_content) + '</div>'
        );

        const $copyBtn    = $('<button class="wnq-btn">📋 Copy to Clipboard</button>');
        const $approveBtn = $('<button class="wnq-btn wnq-btn-primary">✅ Approve Content</button>');
        const $rejectBtn  = $('<button class="wnq-btn wnq-btn-danger" style="margin-right:auto;">✗ Reject</button>');

        $copyBtn.on('click', function () {
          navigator.clipboard.writeText(job.output_content).then(() => {
            $copyBtn.html('✓ Copied!');
            setTimeout(() => $copyBtn.html('📋 Copy to Clipboard'), 2000);
          });
        });

        $approveBtn.on('click', function () {
          $.post(WNQ_SEOHUB.ajaxUrl, {
            action: 'wnq_seohub_action', hub_action: 'approve_job',
            entity_id: jobId, nonce: WNQ_SEOHUB.nonce
          }).done(function (r) {
            if (r.success) {
              $overlay.remove();
              $(btn).closest('tr').find('td:nth-child(3)').html('<span style="color:#16a34a;font-weight:600;">completed ✓ Approved</span>');
              $(btn).closest('td').html('<span style="color:#16a34a;font-size:12px;">Approved</span>');
            }
          });
        });

        $footer.append($rejectBtn).append($copyBtn).append($approveBtn);
      } else {
        $body.html('<p style="color:#dc2626;">Failed to load content.</p>');
      }
    }).fail(() => $body.html('<p style="color:#dc2626;">Network error.</p>'));

    // Close handlers
    $overlay.on('click', '#wnq-close-modal', () => $overlay.remove());
    $overlay.on('click', function (e) {
      if ($(e.target).is($overlay)) $overlay.remove();
    });
    $(document).on('keydown.wnqmodal', function (e) {
      if (e.key === 'Escape') { $overlay.remove(); $(document).off('keydown.wnqmodal'); }
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

  // ── Utility ────────────────────────────────────────────────────────────────

  function wnqEscape(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  // ── AJAX: Get Single Job Content ──────────────────────────────────────────

  if (typeof WNQ_SEOHUB !== 'undefined') {
    // Register the get_job action handler response parser
    // (actual wp_ajax handler added in PHP — this just triggers on modal open)
  }

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
