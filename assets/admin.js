/**
 * WPBan Pro Admin JavaScript
 */

(function($) {
    'use strict';

    // Tab Navigation
    $(document).on('click', '.nav-tab', function(e) {
        e.preventDefault();
        const target = $(this).attr('href');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').removeClass('active');
        $(target).addClass('active');
        
        // Save active tab to localStorage
        localStorage.setItem('wpban_active_tab', target);
    });
    
    // Restore active tab
    $(document).ready(function() {
        const activeTab = localStorage.getItem('wpban_active_tab');
        if (activeTab && $(activeTab).length) {
            $('.nav-tab[href="' + activeTab + '"]').trigger('click');
        }
    });
    
    // Settings Form Handler
    $('#wpban-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $button = $('#wpban-save-settings');
        const $spinner = $button.next('.spinner');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        
        $.ajax({
            url: wpban.ajax_url,
            type: 'POST',
            data: {
                action: 'wpban_save_settings',
                settings: $form.serialize(),
                _ajax_nonce: $('#wpban_nonce').val()
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data.message);
                } else {
                    showMessage('error', response.data || wpban.i18n.error);
                }
            },
            error: function() {
                showMessage('error', wpban.i18n.error);
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Apply Template
    window.wpbanApplyTemplate = function(templateId) {
        if (!confirm(wpban.i18n.confirm_template)) {
            return;
        }
        
        $.post(wpban.ajax_url, {
            action: 'wpban_apply_template',
            template: templateId,
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                showMessage('success', response.data.message);
                setTimeout(() => location.reload(), 1500);
            } else {
                showMessage('error', response.data || wpban.i18n.error);
            }
        });
    };
    
    // Copy Text to Clipboard
    window.wpbanCopyText = function(text) {
        navigator.clipboard.writeText(text).then(() => {
            showMessage('success', 'Copied to clipboard!');
        }).catch(() => {
            // Fallback for older browsers
            const $temp = $('<input>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
            showMessage('success', 'Copied to clipboard!');
        });
    };
    
    // Crawler Selection
    window.wpbanSelectCrawlers = function(type, select) {
        if (type === 'all') {
            $('.wpban-crawler-item input').prop('checked', select);
        } else {
            $(`.wpban-crawler-item[data-type="${type}"] input`).prop('checked', select);
        }
    };
    
    // Export Logs
    window.wpbanExportLogs = function() {
        window.location.href = wpban.ajax_url + '?action=wpban_export_logs&_ajax_nonce=' + wpban.nonce;
    };
    
    // Clear Logs
    window.wpbanClearLogs = function() {
        if (!confirm(wpban.i18n.confirm_clear)) {
            return;
        }
        
        $.post(wpban.ajax_url, {
            action: 'wpban_clear_logs',
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                showMessage('success', response.data.message);
                setTimeout(() => location.reload(), 1500);
            }
        });
    };
    
    // Test Email
    window.wpbanTestEmail = function() {
        const $button = event.target;
        $button.disabled = true;
        
        $.post(wpban.ajax_url, {
            action: 'wpban_test_email',
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                showMessage('success', response.data.message);
            } else {
                showMessage('error', response.data || wpban.i18n.error);
            }
        }).always(function() {
            $button.disabled = false;
        });
    };
    
    // Export Settings
    window.wpbanExportSettings = function() {
        $.get(wpban.ajax_url, {
            action: 'wpban_export_settings',
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                const blob = new Blob([response.data], {type: 'application/json'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'wpban-settings-' + new Date().toISOString().split('T')[0] + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
        });
    };
    
    // Optimize Database
    window.wpbanOptimizeDatabase = function() {
        const $button = event.target;
        $button.disabled = true;
        
        $.post(wpban.ajax_url, {
            action: 'wpban_optimize_database',
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                showMessage('success', 'Database optimized successfully!');
            }
        }).always(function() {
            $button.disabled = false;
        });
    };
    
    // Clear Old Logs
    window.wpbanClearOldLogs = function() {
        if (!confirm('This will permanently delete all logs older than 30 days. Continue?')) {
            return;
        }
        
        $.post(wpban.ajax_url, {
            action: 'wpban_clear_old_logs',
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                showMessage('success', response.data.message);
                setTimeout(() => location.reload(), 1500);
            }
        });
    };
    
    // Live Search for Crawlers
    let searchTimeout;
    $(document).on('input', '#wpban-crawler-search', function() {
        const query = $(this).val().toLowerCase();
        clearTimeout(searchTimeout);
        
        searchTimeout = setTimeout(() => {
            $('.wpban-crawler-item').each(function() {
                const $item = $(this);
                const text = $item.text().toLowerCase();
                $item.toggle(text.indexOf(query) > -1);
            });
        }, 300);
    });
    
    // Real-time Log Updates (optional)
    if ($('#wpban-logs-container').length) {
        // Auto-refresh logs every 30 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                refreshLogs();
            }
        }, 30000);
    }
    
    function refreshLogs() {
        const params = new URLSearchParams(window.location.search);
        
        $.get(wpban.ajax_url, {
            action: 'wpban_get_logs',
            page: params.get('paged') || 1,
            date_from: params.get('date_from'),
            date_to: params.get('date_to'),
            action_filter: params.get('action_filter'),
            ip_filter: params.get('ip_filter'),
            _ajax_nonce: wpban.nonce
        }, function(response) {
            if (response.success) {
                $('#wpban-logs-container tbody').html(response.data.html);
            }
        });
    }
    
    // Show Message
    function showMessage(type, message) {
        const $message = $('<div>')
            .addClass('wpban-message ' + type)
            .text(message)
            .prependTo('.wpban-wrap')
            .hide()
            .fadeIn();
        
        setTimeout(() => {
            $message.fadeOut(() => $message.remove());
        }, 5000);
    }
    
    // Geo Blocking Mode Toggle
    $('input[name="settings[geo_blocking][mode]"]').on('change', function() {
        const mode = $(this).val();
        const $label = $('.wpban-country-selector').prev('th').find('label');
        
        if (mode === 'whitelist') {
            $label.text('Allowed Countries');
        } else {
            $label.text('Blocked Countries');
        }
    });
    
    // Initialize Select2 for country selector (if available)
    if ($.fn.select2) {
        $('.wpban-country-selector select').select2({
            placeholder: 'Select countries...',
            width: '100%'
        });
    }
    
    // Handle browser restrictions toggle
    $('input[name="settings[browser_restrictions][wechat_qq][enabled]"]').on('change', function() {
        const $fields = $(this).closest('table').find('tr').not(':first');
        $fields.toggle($(this).is(':checked'));
    }).trigger('change');
    
    // Email notifications toggle
    $('input[name="settings[email_notifications][enabled]"]').on('change', function() {
        const $fields = $(this).closest('table').find('tr').not(':first');
        $fields.toggle($(this).is(':checked'));
    }).trigger('change');
    
    // Geo blocking toggle
    $('input[name="settings[geo_blocking][enabled]"]').on('change', function() {
        const $fields = $(this).closest('table').find('tr').not(':first');
        $fields.toggle($(this).is(':checked'));
    }).trigger('change');
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            $('#wpban-save-settings').trigger('click');
        }
    });
    
    // Tooltip initialization
    $('.wpban-tooltip').tooltip({
        position: {
            my: 'center bottom-10',
            at: 'center top'
        }
    });
    
    // Confirm before leaving with unsaved changes
    let formChanged = false;
    $('#wpban-settings-form').on('change', 'input, select, textarea', function() {
        formChanged = true;
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    $('#wpban-save-settings').on('click', function() {
        formChanged = false;
    });

})(jQuery);