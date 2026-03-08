/**
 * Planning Index Jobs – Interactive Dashboard
 * Handles search, filtering, stat counters, progress bar animations, and mobile labels.
 */
jQuery(function($) {
  'use strict';

  var $wrapper = $('.pi-jobs-page');
  if (!$wrapper.length) return;

  // ─── Mobile data-labels ───
  var headers = [];
  $wrapper.find('.pi-table thead th').each(function() {
    headers.push($(this).text().trim());
  });
  $wrapper.find('.pi-table tbody tr').each(function() {
    $(this).find('td').each(function(i) {
      if (headers[i]) $(this).attr('data-label', headers[i]);
    });
  });

  // ─── Stat counter animation ───
  var countersAnimated = false;

  function animateCounters() {
    if (countersAnimated) return;
    countersAnimated = true;

    $wrapper.find('.pi-jobs-stat-value[data-count]').each(function() {
      var $el = $(this);
      var target = parseInt($el.data('count'), 10) || 0;
      if (target === 0) { $el.text('0'); return; }

      var duration = 600;
      var start = performance.now();

      function step(now) {
        var elapsed = now - start;
        var progress = Math.min(elapsed / duration, 1);
        // ease-out cubic
        var ease = 1 - Math.pow(1 - progress, 3);
        $el.text(Math.round(target * ease));
        if (progress < 1) requestAnimationFrame(step);
      }

      requestAnimationFrame(step);
    });
  }

  // Use IntersectionObserver if available, else animate immediately
  if ('IntersectionObserver' in window) {
    var statsGrid = $wrapper.find('.pi-jobs-stats-grid')[0];
    if (statsGrid) {
      var obs = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
          animateCounters();
          obs.disconnect();
        }
      }, { threshold: 0.3 });
      obs.observe(statsGrid);
    }
  } else {
    animateCounters();
  }

  // ─── Search & Filter Toolbar ───
  var $card = $wrapper.find('.pi-card').first();
  var $tableBody = $card.find('.pi-table tbody');

  var toolbarHtml =
    '<div class="pi-jobs-toolbar">' +
      '<div class="pi-jobs-search">' +
        '<svg class="pi-jobs-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>' +
        '<input type="text" id="pi-jobs-search" placeholder="Search jobs…" autocomplete="off" />' +
      '</div>' +
      '<div class="pi-jobs-filter-btns">' +
        '<button class="pi-jobs-filter-btn active" data-filter="all" type="button">All</button>' +
        '<button class="pi-jobs-filter-btn" data-filter="planning" type="button">Planning</button>' +
        '<button class="pi-jobs-filter-btn" data-filter="active" type="button">Active</button>' +
        '<button class="pi-jobs-filter-btn" data-filter="completed" type="button">Completed</button>' +
      '</div>' +
    '</div>';

  $card.find('.pi-card-header').after(toolbarHtml);

  // No-results element
  var noResultsHtml =
    '<div class="pi-jobs-no-results" style="display:none;">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>' +
      '<span>No jobs match your search.</span>' +
    '</div>';
  $card.find('.pi-table-wrapper').after(noResultsHtml);
  var $noResults = $card.find('.pi-jobs-no-results');

  var currentFilter = 'all';

  function applyFilters() {
    var searchVal = $('#pi-jobs-search').val().toLowerCase();
    var visible = 0;

    $tableBody.find('tr').each(function() {
      var $row = $(this);
      var text = $row.text().toLowerCase();
      var status = '';
      var $badge = $row.find('.pi-badge');
      if ($badge.length) status = $badge.text().trim().toLowerCase();

      var matchesSearch = !searchVal || text.indexOf(searchVal) > -1;
      var matchesFilter = currentFilter === 'all' || status === currentFilter;

      if (matchesSearch && matchesFilter) {
        $row.show();
        visible++;
      } else {
        $row.hide();
      }
    });

    // Show/hide no-results message
    if ($tableBody.find('tr').length > 0) {
      if (visible === 0) {
        $noResults.show();
        $card.find('.pi-table-wrapper').hide();
      } else {
        $noResults.hide();
        $card.find('.pi-table-wrapper').show();
      }
    }
  }

  $('#pi-jobs-search').on('input', function() {
    applyFilters();
  });

  $(document).on('click', '.pi-jobs-filter-btn', function() {
    $('.pi-jobs-filter-btn').removeClass('active');
    $(this).addClass('active');
    currentFilter = $(this).data('filter');
    applyFilters();
  });

  // ─── Progress bar animation ───
  $wrapper.find('.pi-progress-bar-fill').each(function() {
    var $fill = $(this);
    var targetWidth = $fill[0].style.width;
    $fill.css('width', '0%');
    setTimeout(function() {
      $fill.css('width', targetWidth);
    }, 400);
  });

  // ─── Row hover + details sidebar ───
  $tableBody.find('tr').css('cursor', 'pointer');

  function renderJobDetails(job) {
    var $card = $('#pi-jobs-detail-card');
    var $empty = $card.find('.pi-jobs-detail-empty');
    var $content = $card.find('.pi-jobs-detail-content');

    if (!job || !job.id) {
      $content.hide().empty();
      $empty.show();
      $('#pi-jobs-timeline').html('<div class="pi-jobs-timeline-empty"><span>No job selected.</span></div>');
      return;
    }

    $empty.hide();

    var statusLabel = job.status ? job.status.charAt(0).toUpperCase() + job.status.slice(1) : 'Planning';
    var progress = parseInt(job.progress || 0, 10);
    if (progress < 0 || isNaN(progress)) progress = 0;
    if (progress > 100) progress = 100;

    var start = job.start_date || '—';
    var end = job.end_date || '—';
    var customer = job.customer_name || '—';
    var address = job.site_address || '—';

    var detailHtml =
      '<div class="pi-jobs-detail-heading">' +
        '<span class="pi-jobs-detail-code">' + String(job.code || ('JOB-' + job.id)) + '</span>' +
        '<span class="pi-jobs-detail-status">' +
          '<span class="pi-badge pi-badge-status-' + (job.status || 'planning') + '">' + statusLabel + '</span>' +
        '</span>' +
      '</div>' +
      '<div class="pi-jobs-detail-progress-ring" style="grid-column: 1 / -1;">' +
        '<div class="pi-jobs-detail-progress-meter" style="--pi-progress:' + progress + ';">' +
          '<span class="pi-jobs-detail-progress-value">' + progress + '%</span>' +
        '</div>' +
        '<div class="pi-jobs-detail-progress-copy">' +
          '<span>Overall progress</span>' +
          '<span>Track on-site delivery and completion.</span>' +
        '</div>' +
      '</div>' +
      '<div>' +
        '<div class="pi-jobs-detail-grid-label">Customer</div>' +
        '<div class="pi-jobs-detail-grid-value">' + customer + '</div>' +
      '</div>' +
      '<div>' +
        '<div class="pi-jobs-detail-grid-label">Status</div>' +
        '<div class="pi-jobs-detail-grid-value">' + statusLabel + '</div>' +
      '</div>' +
      '<div>' +
        '<div class="pi-jobs-detail-grid-label">Start date</div>' +
        '<div class="pi-jobs-detail-grid-value">' + start + '</div>' +
      '</div>' +
      '<div>' +
        '<div class="pi-jobs-detail-grid-label">Target finish</div>' +
        '<div class="pi-jobs-detail-grid-value">' + end + '</div>' +
      '</div>' +
      '<div class="pi-jobs-detail-address">' +
        '<div class="pi-jobs-detail-grid-label">Site address</div>' +
        '<div class="pi-jobs-detail-grid-value">' + address + '</div>' +
      '</div>';

    $content.html(detailHtml).show();

    // Timeline
    var $timeline = $('#pi-jobs-timeline');
    var activity = Array.isArray(job.activity) ? job.activity : [];
    if (!activity.length) {
      $timeline.html('<div class="pi-jobs-timeline-empty"><span>No activity recorded yet.</span></div>');
      return;
    }

    var itemsHtml = '<ul class="pi-jobs-timeline-list">';
    activity.slice().reverse().forEach(function(entry) {
      var parts = String(entry).split(': ');
      var ts = parts.shift() || '';
      var text = parts.join(': ') || entry;
      itemsHtml +=
        '<li class="pi-jobs-timeline-item">' +
          '<div class="pi-jobs-timeline-dot"></div>' +
          '<div class="pi-jobs-timeline-meta">' + ts + '</div>' +
          '<div class="pi-jobs-timeline-text">' + text + '</div>' +
        '</li>';
    });
    itemsHtml += '</ul>';
    $timeline.html(itemsHtml);
  }

  function loadJobDetails(jobId) {
    if (!window.PI_Jobs || !PI_Jobs.rest_base) {
      return;
    }
    var url = PI_Jobs.rest_base.replace(/\/+$/, '') + '/' + encodeURIComponent(jobId);

    // Simple loading state
    $('#pi-jobs-detail-card .pi-jobs-detail-empty').hide();
    $('#pi-jobs-detail-card .pi-jobs-detail-content')
      .show()
      .html('<div class="pi-jobs-detail-grid-label">Loading job details…</div>');
    $('#pi-jobs-timeline').html('<div class="pi-jobs-timeline-empty"><span>Loading activity…</span></div>');

    fetch(url, {
      method: 'GET',
      headers: {
        'X-WP-Nonce': PI_Jobs.nonce || ''
      }
    }).then(function(res) {
      return res.json();
    }).then(function(data) {
      renderJobDetails(data || {});
    }).catch(function() {
      $('#pi-jobs-timeline').html('<div class="pi-jobs-timeline-empty"><span>Unable to load job activity.</span></div>');
    });
  }

  $tableBody.on('click', 'tr[data-job-id]', function() {
    var id = $(this).data('job-id');
    if (!id) return;
    $tableBody.find('tr').removeClass('pi-jobs-row-selected');
    $(this).addClass('pi-jobs-row-selected');
    loadJobDetails(id);
  });
});

