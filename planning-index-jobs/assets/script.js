/**
 * PlanningIndex Jobs - Interactive Dashboard
 * Version: 2.0.0
 *
 * Handles:
 * - Search and filtering
 * - Row click interactions
 * - Sidebar detail loading
 * - Counter animations
 * - Smooth UI transitions
 */

(function($) {
  'use strict';

  const JobsDashboard = {

    $wrapper: null,
    $searchInput: null,
    $filterBar: null,
    $filterBtn: null,
    $filterOptions: null,
    $tableBody: null,
    $sidebar: null,
    $sidebarEmpty: null,
    $sidebarContent: null,
    currentFilter: 'all',
    currentJobId: null,

    init: function() {
      this.$wrapper = $('.pi-jobs-wrapper');

      if (!this.$wrapper.length) {
        return;
      }

      this.cacheElements();
      this.bindEvents();
      this.animateStats();
      this.animateProgressBars();
    },

    cacheElements: function() {
      this.$searchInput = $('#pi-jobs-search-input');
      this.$filterBar = $('#pi-jobs-filter-bar');
      this.$filterBtn = $('#pi-jobs-filter-btn');
      this.$filterOptions = $('.pi-jobs-filter-option');
      this.$tableBody = $('.pi-jobs-table tbody');
      this.$sidebar = $('#pi-jobs-sidebar');
      this.$sidebarEmpty = $('#pi-jobs-sidebar-empty');
      this.$sidebarContent = $('#pi-jobs-sidebar-content');
    },

    bindEvents: function() {
      this.$searchInput.on('input', this.handleSearch.bind(this));
      this.$filterBtn.on('click', this.toggleFilterBar.bind(this));
      this.$filterOptions.on('click', this.handleFilterChange.bind(this));
      this.$tableBody.on('click', 'tr[data-job-id]', this.handleRowClick.bind(this));
    },

    toggleFilterBar: function(e) {
      e.preventDefault();
      this.$filterBar.slideToggle(200);
    },

    handleSearch: function() {
      const searchTerm = this.$searchInput.val().toLowerCase();
      this.applyFilters(searchTerm, this.currentFilter);
    },

    handleFilterChange: function(e) {
      const $btn = $(e.currentTarget);
      const filter = $btn.data('filter');

      this.$filterOptions.removeClass('active');
      $btn.addClass('active');

      this.currentFilter = filter;

      const searchTerm = this.$searchInput.val().toLowerCase();
      this.applyFilters(searchTerm, filter);
    },

    applyFilters: function(searchTerm, statusFilter) {
      let visibleCount = 0;

      this.$tableBody.find('tr[data-job-id]').each(function() {
        const $row = $(this);
        const rowText = $row.text().toLowerCase();
        const $badge = $row.find('.pi-jobs-badge');
        const status = $badge.length ? $badge.text().trim().toLowerCase() : '';

        const matchesSearch = !searchTerm || rowText.indexOf(searchTerm) !== -1;
        const matchesFilter = statusFilter === 'all' || status === statusFilter;

        if (matchesSearch && matchesFilter) {
          $row.show();
          visibleCount++;
        } else {
          $row.hide();
        }
      });

      if (visibleCount === 0 && this.$tableBody.find('tr').length > 0) {
        this.showNoResults();
      } else {
        this.hideNoResults();
      }
    },

    showNoResults: function() {
      const $table = $('.pi-jobs-table-card');
      if (!$table.find('.pi-jobs-no-results').length) {
        const noResultsHtml =
          '<div class="pi-jobs-no-results" style="padding: 60px 32px; text-align: center;">' +
            '<svg style="width: 48px; height: 48px; color: var(--pi-gray-300); stroke-width: 1.5; margin: 0 auto 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor">' +
              '<circle cx="11" cy="11" r="8"/>' +
              '<path d="m21 21-4.35-4.35"/>' +
            '</svg>' +
            '<p style="font-size: 14px; color: var(--pi-gray-500); margin: 0;">No jobs match your search criteria.</p>' +
          '</div>';

        $('.pi-jobs-table-wrapper').after(noResultsHtml);
      }
      $('.pi-jobs-table-wrapper').hide();
      $('.pi-jobs-no-results').show();
    },

    hideNoResults: function() {
      $('.pi-jobs-table-wrapper').show();
      $('.pi-jobs-no-results').remove();
    },

    handleRowClick: function(e) {
      const $row = $(e.currentTarget);
      const jobId = $row.data('job-id');

      if (!jobId) {
        return;
      }

      this.$tableBody.find('tr').removeClass('pi-jobs-row-selected');
      $row.addClass('pi-jobs-row-selected');

      this.currentJobId = jobId;
      this.loadJobDetails(jobId);
    },

    loadJobDetails: function(jobId) {
      if (!window.PI_Jobs || !PI_Jobs.rest_base) {
        return;
      }

      const url = PI_Jobs.rest_base.replace(/\/+$/, '') + '/' + jobId;

      this.$sidebarEmpty.hide();
      this.$sidebarContent.show().html(this.getLoadingHTML());

      fetch(url, {
        method: 'GET',
        headers: {
          'X-WP-Nonce': PI_Jobs.nonce || ''
        }
      })
      .then(response => response.json())
      .then(data => {
        this.renderJobDetails(data);
      })
      .catch(error => {
        console.error('Error loading job details:', error);
        this.$sidebarContent.html(
          '<div style="padding: 24px; text-align: center; color: var(--pi-gray-500);">' +
            '<p>Unable to load job details.</p>' +
          '</div>'
        );
      });
    },

    getLoadingHTML: function() {
      return '<div style="padding: 24px; text-align: center; color: var(--pi-gray-400);">' +
               '<p>Loading job details...</p>' +
             '</div>';
    },

    renderJobDetails: function(job) {
      if (!job || !job.id) {
        this.$sidebarContent.hide();
        this.$sidebarEmpty.show();
        return;
      }

      const statusLabel = job.status ? this.capitalizeFirst(job.status) : 'Planning';
      const progress = Math.max(0, Math.min(100, parseInt(job.progress, 10) || 0));

      const html =
        '<div class="pi-jobs-detail-section">' +
          '<div class="pi-jobs-detail-header">' +
            '<h3 class="pi-jobs-detail-title">Job Overview</h3>' +
            '<span class="pi-jobs-detail-code-badge">' + this.escapeHtml(job.code || 'JOB-' + job.id) + '</span>' +
          '</div>' +
          '<div class="pi-jobs-detail-progress">' +
            '<div class="pi-jobs-progress-ring" style="--progress: ' + progress + ';">' +
              '<span class="pi-jobs-progress-value">' + progress + '%</span>' +
            '</div>' +
            '<div class="pi-jobs-progress-info">' +
              '<div class="pi-jobs-progress-label">Overall Progress</div>' +
              '<div class="pi-jobs-progress-desc">Track on-site delivery and completion</div>' +
            '</div>' +
          '</div>' +
          '<div class="pi-jobs-detail-grid">' +
            '<div class="pi-jobs-detail-item">' +
              '<div class="pi-jobs-detail-label">Client</div>' +
              '<div class="pi-jobs-detail-value">' + this.escapeHtml(job.customer_name || '—') + '</div>' +
            '</div>' +
            '<div class="pi-jobs-detail-item">' +
              '<div class="pi-jobs-detail-label">Status</div>' +
              '<div class="pi-jobs-detail-value">' +
                '<span class="pi-jobs-badge pi-jobs-badge-' + (job.status || 'planning') + '">' +
                  statusLabel +
                '</span>' +
              '</div>' +
            '</div>' +
            '<div class="pi-jobs-detail-item">' +
              '<div class="pi-jobs-detail-label">Start Date</div>' +
              '<div class="pi-jobs-detail-value">' + this.escapeHtml(job.start_date || '—') + '</div>' +
            '</div>' +
            '<div class="pi-jobs-detail-item">' +
              '<div class="pi-jobs-detail-label">Target Finish</div>' +
              '<div class="pi-jobs-detail-value">' + this.escapeHtml(job.end_date || '—') + '</div>' +
            '</div>' +
            '<div class="pi-jobs-detail-item full-width">' +
              '<div class="pi-jobs-detail-label">Site Address</div>' +
              '<div class="pi-jobs-detail-value">' + this.escapeHtml(job.site_address || '—') + '</div>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div class="pi-jobs-detail-section">' +
          '<h3 class="pi-jobs-detail-title">Activity Timeline</h3>' +
          this.renderTimeline(job.activity || []) +
        '</div>';

      this.$sidebarContent.html(html);
    },

    renderTimeline: function(activity) {
      if (!Array.isArray(activity) || activity.length === 0) {
        return '<div style="padding: 16px; text-align: center; color: var(--pi-gray-400); font-size: 13px;">' +
                 '<p>No activity recorded yet.</p>' +
               '</div>';
      }

      let html = '<div class="pi-jobs-timeline">';

      activity.slice().reverse().forEach(entry => {
        const parts = String(entry).split(': ');
        const timestamp = parts.shift() || '';
        const text = parts.join(': ') || entry;

        html +=
          '<div class="pi-jobs-timeline-item">' +
            '<div class="pi-jobs-timeline-dot"></div>' +
            '<div class="pi-jobs-timeline-meta">' + this.escapeHtml(timestamp) + '</div>' +
            '<div class="pi-jobs-timeline-text">' + this.escapeHtml(text) + '</div>' +
          '</div>';
      });

      html += '</div>';
      return html;
    },

    animateStats: function() {
      const $statValues = $('.pi-jobs-stat-value[data-count]');

      if (!$statValues.length) {
        return;
      }

      const observer = new IntersectionObserver(
        entries => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              this.animateCounter($(entry.target));
              observer.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.3 }
      );

      $statValues.each(function() {
        observer.observe(this);
      });
    },

    animateCounter: function($element) {
      const target = parseInt($element.data('count'), 10) || 0;

      if (target === 0) {
        $element.text('0');
        return;
      }

      const duration = 800;
      const start = performance.now();

      const step = now => {
        const elapsed = now - start;
        const progress = Math.min(elapsed / duration, 1);
        const easeOut = 1 - Math.pow(1 - progress, 3);

        $element.text(Math.round(target * easeOut));

        if (progress < 1) {
          requestAnimationFrame(step);
        }
      };

      requestAnimationFrame(step);
    },

    animateProgressBars: function() {
      const $fills = $('.pi-jobs-progress-fill');

      $fills.each(function() {
        const $fill = $(this);
        const targetWidth = $fill[0].style.width;

        $fill.css('width', '0%');

        setTimeout(() => {
          $fill.css('width', targetWidth);
        }, 100);
      });
    },

    capitalizeFirst: function(str) {
      if (!str) return '';
      return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    },

    escapeHtml: function(text) {
      if (!text) return '';
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  };

  $(document).ready(function() {
    JobsDashboard.init();
  });

})(jQuery);
