/**
 * Admin Panel JavaScript Controller for Shopwalker.
 * Handles Live Monitor scanner modal, AJAX API connection tests, settings tabs, toast alerts, and database inspector.
 *
 * @package Shopwalker
 */

jQuery(document).ready(function ($) {
    // Escape server-provided text before inserting it as HTML.
    function aivaEsc(value) {
        if (value !== null && typeof value === 'object') {
            value = value.message || value.error || JSON.stringify(value);
        }
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    // SVG Icons for clean status badges & alerts
    var svgSuccess = '<svg class="aiva-svg-status success" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>';
    var svgError = '<svg class="aiva-svg-status error" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>';
    var svgWarning = '<svg class="aiva-svg-status warning" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>';
    var svgCheck = '<svg viewBox="0 0 20 20" fill="#ffffff" style="width:14px;height:14px;display:inline-block;vertical-align:middle;flex-shrink:0;"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    var svgBtnUpdate = '<svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg>';
    var svgBtnTrash = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 15px; height: 15px; margin-right: 4px; vertical-align: -2px;"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>';
    var svgBtnPlay = '<svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>';

    // Custom AIVA Branded Toast Notification System
    function showAivaToast(message, type, duration) {
        type = type || 'success';
        duration = duration || 3200;

        var $container = $('#aiva-toast-container');
        if (!$container.length) {
            $container = $('<div id="aiva-toast-container"></div>').appendTo('body');
        }

        var iconHtml = (type === 'success') ? svgCheck : svgWarning;
        var $toast = $(
            '<div class="aiva-toast-popup aiva-toast-' + type + '">' +
                '<div class="aiva-toast-body">' +
                    '<div class="aiva-toast-icon">' + iconHtml + '</div>' +
                    '<span class="aiva-toast-msg">' + message + '</span>' +
                '</div>' +
                '<button type="button" class="aiva-toast-close">&times;</button>' +
            '</div>'
        );

        $container.append($toast);

        setTimeout(function() {
            $toast.addClass('aiva-toast-show');
        }, 10);

        var timer = setTimeout(function() {
            removeToast();
        }, duration);

        function removeToast() {
            clearTimeout(timer);
            $toast.removeClass('aiva-toast-show');
            setTimeout(function() {
                $toast.remove();
            }, 300);
        }

        $toast.find('.aiva-toast-close').on('click', removeToast);
    }
    // 1. Vertical Sidebar Tab Navigation for Settings Page
    $('.aiva-sidebar-tabs a').on('click', function (e) {
        e.preventDefault();

        // Active tab item styling
        $('.aiva-sidebar-tabs li').removeClass('active');
        $(this).parent('li').addClass('active');

        // Show corresponding section
        var targetHash = $(this).attr('href'); // e.g. '#api'
        var tabKey = targetHash.replace('#', ''); // e.g. 'api'
        var targetSection = '#tab-' + tabKey; // e.g. '#tab-api'

        $('.aiva-settings-panel').removeClass('active');
        $(targetSection).addClass('active');

        // Update hidden field for form POST persistence
        $('#aiva_active_tab').val(tabKey);

        // Keep hash in URL & localStorage for page refreshes
        window.location.hash = targetHash;
        try {
            localStorage.setItem('aiva_active_tab', targetHash);
        } catch (err) { }
    });

    // Toggle custom model ID text input when "custom" option is selected
    $(document).on('change', '.aiva-model-select', function () {
        var targetId = $(this).attr('data-target-custom');
        if (targetId) {
            if ($(this).val() === 'custom') {
                $('#' + targetId).fadeIn(200);
            } else {
                $('#' + targetId).fadeOut(150);
            }
        }
    });

    // Handle hash or localStorage on page load for vertical tabs
    try {
        var currentHash = window.location.hash;
        if (!currentHash) {
            currentHash = localStorage.getItem('aiva_active_tab');
        }

        if (currentHash && typeof currentHash === 'string' && currentHash.indexOf('#') === 0 && currentHash.length > 1) {
            var safeSelector = '.aiva-sidebar-tabs a[href="' + currentHash.replace(/"/g, '\\"') + '"]';
            if ($(safeSelector).length) {
                $(safeSelector).trigger('click');
            }
        }
    } catch (err) {
        // Ignore hash syntax errors gracefully
    }

    // Isolate & relocate third-party notices (e.g. Elementor, theme updates) above hero header
    function isolateAdminNotices() {
        var $wrap = $('.aiva-admin-wrap');
        if (!$wrap.length) return;

        var $noticesContainer = $('.aiva-notices-container');
        if (!$noticesContainer.length) {
            $noticesContainer = $('<div class="aiva-notices-container"></div>').insertBefore('.aiva-header');
        }

        $('.aiva-header').find('.notice, .updated, .error, .e-notice, div[class*="elementor"], div[class*="notice"]').each(function () {
            $(this).appendTo($noticesContainer);
        });

        $wrap.children('.notice, .updated, .error, .e-notice, div[class*="elementor"], div[class*="notice"]').each(function () {
            if (!$(this).hasClass('aiva-notices-container')) {
                $(this).appendTo($noticesContainer);
            }
        });
    }

    isolateAdminNotices();
    setTimeout(isolateAdminNotices, 300);
    setTimeout(isolateAdminNotices, 1000);

    // Toggle checked class on post type cards
    $(document).on('change', '.aiva-post-type-card input[type="checkbox"]', function () {
        if ($(this).is(':checked')) {
            $(this).closest('.aiva-post-type-card').addClass('checked');
        } else {
            $(this).closest('.aiva-post-type-card').removeClass('checked');
        }
    });

    // Toggle active class on target content type cards
    $(document).on('change', '.aiva-target-card input[type="checkbox"]', function () {
        if ($(this).is(':checked')) {
            $(this).closest('.aiva-target-card').addClass('active');
        } else {
            $(this).closest('.aiva-target-card').removeClass('active');
        }
    });

    // Quick Path insert/remove toggle chip handler
    function syncPathChips() {
        var val = $('#aiva_excluded_urls').val() || '';
        $('.aiva-quick-path-btn').each(function () {
            var path = $(this).attr('data-path');
            var label = $(this).attr('data-label') || path;
            if (val.indexOf(path) !== -1) {
                $(this).addClass('active').html(svgCheck + ' ' + label);
            } else {
                $(this).removeClass('active').html('+ ' + label);
            }
        });
    }

    $(document).on('click', '.aiva-quick-path-btn', function (e) {
        e.preventDefault();
        var path = $(this).attr('data-path');
        var textarea = $('#aiva_excluded_urls');
        var val = textarea.val();

        if ($(this).hasClass('active')) {
            // Remove path snippet
            var lines = val.split('\n').filter(function (line) {
                return line.trim() !== path;
            });
            textarea.val(lines.join('\n').trim());
        } else {
            // Append path snippet
            var trimVal = val.trim();
            textarea.val(trimVal ? trimVal + '\n' + path : path);
        }
        syncPathChips();
    });

    // Persona Template insert/remove toggle chip handler
    function syncPersonaChips() {
        var val = $('#aiva_business_instructions').val() || '';
        $('.aiva-persona-btn').each(function () {
            var persona = $(this).attr('data-persona');
            var label = $(this).attr('data-label') || persona;
            if (val.indexOf(persona) !== -1) {
                $(this).addClass('active').html(svgCheck + ' ' + label);
            } else {
                $(this).removeClass('active').html('+ ' + label);
            }
        });
    }

    $(document).on('click', '.aiva-persona-btn', function (e) {
        e.preventDefault();
        var persona = $(this).attr('data-persona');
        var textarea = $('#aiva_business_instructions');
        var val = textarea.val();

        if ($(this).hasClass('active')) {
            // Remove persona snippet
            var newVal = val.replace(persona, '').replace(/\s+/g, ' ').trim();
            textarea.val(newVal);
        } else {
            // Append persona snippet
            var trimVal = val.trim();
            textarea.val(trimVal ? trimVal + ' ' + persona : persona);
        }
        syncPersonaChips();
    });

    // Sync chip states on load & textarea input
    syncPathChips();
    syncPersonaChips();
    $(document).on('input', '#aiva_excluded_urls', syncPathChips);
    $(document).on('input', '#aiva_business_instructions', syncPersonaChips);

    // Remove Key trash button handler
    $(document).on('click', '.aiva-key-remove-btn', function (e) {
        e.preventDefault();
        var targetId = $(this).attr('data-target');
        if (targetId) {
            $('#' + targetId).val('').trigger('input');
            $(this).fadeOut();
        }
    });

    // Playback Mode card change toggle
    $(document).on('change', 'input[name="aiva_tts_engine"]', function () {
        var mode = $(this).val();
        if (mode === 'browser') {
            $('#aiva-elevenlabs-settings-wrapper').slideUp(200);
        } else {
            $('#aiva-elevenlabs-settings-wrapper').slideDown(200);
        }
    });

    // Chip & Card selection radio toggles
    $(document).on('click', '.aiva-trigger-chip, .aiva-engine-chip, .aiva-pos-card, .aiva-mode-card', function () {
        var group = $(this).closest('.aiva-trigger-chips-grid, .aiva-selector-chips-grid, .aiva-row-fields, .aiva-mode-grid');
        group.find('.aiva-trigger-chip, .aiva-engine-chip, .aiva-pos-card, .aiva-mode-card').removeClass('active');
        $(this).addClass('active');
        $(this).find('input[type="radio"]').prop('checked', true).trigger('change');
    });

    // 2. Scanner Variables & Terminal Monitor State
    var scanQueue = [];
    var totalItemsCount = 0;
    var processedItemsCount = 0;
    var isScanning = false;
    var scanStartTime = null;
    var scanTimerInterval = null;
    var activeScanXHR = null;

    // Log printer helper (writes to both in-page box & live terminal monitor modal)
    function addLog(message, type) {
        type = type || 'info';
        var timeStr = '[' + new Date().toLocaleTimeString() + '] ';

        // In-page log box
        var logBox = $('#aiva-scan-logs');
        if (logBox.length) {
            var logElement = $('<div></div>').text(timeStr + message);
            if (type === 'success') {
                logElement.addClass('success');
            } else if (type === 'error') {
                logElement.addClass('error');
            }
            logBox.append(logElement);
            if (logBox[0]) {
                logBox.scrollTop(logBox[0].scrollHeight);
            }
        }

        // Live Terminal Modal log box
        var modalTerminal = $('#aiva-modal-terminal-logs');
        if (modalTerminal.length) {
            var modalLogElement = $('<div></div>').text(timeStr + message);
            if (type === 'success') {
                modalLogElement.addClass('success');
            } else if (type === 'error') {
                modalLogElement.addClass('error');
            }
            modalTerminal.append(modalLogElement);
            if (modalTerminal[0]) {
                modalTerminal.scrollTop(modalTerminal[0].scrollHeight);
            }
        }
    }

    // Update Progress Bar & Real-time Metrics
    function updateProgress() {
        var percentage = totalItemsCount > 0 ? Math.round((processedItemsCount / totalItemsCount) * 100) : 0;

        // In-page UI
        $('#aiva-scan-progress-bar').css('width', percentage + '%');
        $('#aiva-scan-status-text').text('Scanning: ' + processedItemsCount + ' of ' + totalItemsCount + ' items processed (' + percentage + '%)');

        // Terminal Modal Metrics
        $('#aiva-modal-progress-bar').css('width', percentage + '%');
        $('#aiva-modal-stat-total').text(totalItemsCount);
        $('#aiva-modal-stat-processed').text(processedItemsCount);
        $('#aiva-modal-stat-percent').text(percentage + '%');
        $('#aiva-modal-status-text').text('Scanning: ' + processedItemsCount + ' of ' + totalItemsCount + ' content items indexed (' + percentage + '%)');

        // Floating Dock Bar
        $('#aiva-float-meta').text(processedItemsCount + ' of ' + totalItemsCount + ' items (' + percentage + '%)');
    }

    // Live Scan Timer
    function startScanTimer() {
        scanStartTime = Date.now();
        if (scanTimerInterval) clearInterval(scanTimerInterval);
        scanTimerInterval = setInterval(function () {
            if (!scanStartTime) return;
            var elapsedSecs = Math.floor((Date.now() - scanStartTime) / 1000);
            var mins = Math.floor(elapsedSecs / 60);
            var secs = elapsedSecs % 60;
            var formatted = (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
            $('#aiva-modal-stat-time').text(formatted);
        }, 1000);
    }

    function stopScanTimer() {
        if (scanTimerInterval) {
            clearInterval(scanTimerInterval);
            scanTimerInterval = null;
        }
    }

    // Modal & Floating Bar Control Handlers
    $(document).on('click', '#aiva-open-scan-modal, #aiva-expand-modal-btn, #aiva-float-open-modal', function (e) {
        e.preventDefault();
        $('#aiva-scan-modal-overlay').fadeIn(200);
        $('#aiva-floating-scan-bar').fadeOut(200);
    });

    $(document).on('click', '#aiva-minimize-modal, #aiva-close-modal', function (e) {
        e.preventDefault();
        $('#aiva-scan-modal-overlay').fadeOut(200);
        if (isScanning) {
            $('#aiva-floating-scan-bar').fadeIn(200);
        }
    });

    $(document).on('click', '#aiva-copy-modal-logs', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var originalHtml = $btn.data('orig-html');
        if (!originalHtml) {
            originalHtml = $btn.html();
            $btn.data('orig-html', originalHtml);
        }

        var logsText = $('#aiva-modal-terminal-logs').text() || '';

        function copySuccess() {
            $btn.addClass('aiva-btn-copied').html('<span class="dashicons dashicons-yes"></span> Copied!');
            showAivaToast('Terminal logs copied to clipboard!', 'success');
            setTimeout(function () {
                $btn.removeClass('aiva-btn-copied').html(originalHtml);
            }, 2500);
        }

        function fallbackCopy() {
            try {
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(logsText).select();
                document.execCommand('copy');
                $temp.remove();
                copySuccess();
            } catch (err) {
                showAivaToast('Failed to copy logs automatically.', 'warning');
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(logsText).then(function () {
                copySuccess();
            }).catch(function() {
                fallbackCopy();
            });
        } else {
            fallbackCopy();
        }
    });

    // Start Scanning Process
    $('#aiva-start-scan').on('click', function () {
        if (isScanning) return;

        // Visual states
        isScanning = true;
        activeScanXHR = null;
        $('#aiva-start-scan').prop('disabled', true).text('Indexing...');
        $('#aiva-stop-scan-modal-btn, #aiva-float-stop-scan').css('display', 'inline-flex').show();
        $('#aiva-clear-index').prop('disabled', true);
        $('#aiva-open-scan-modal').show();
        $('#aiva-scan-progress-container').show();
        $('#aiva-floating-scan-bar').fadeIn(300);
        $('#aiva-scan-logs').empty();
        $('#aiva-modal-terminal-logs').empty();
        $('#aiva-scan-progress-bar').css('width', '0%');
        $('#aiva-modal-progress-bar').css('width', '0%');
        $('#aiva-scan-status-text').text('Initializing scanner...');

        addLog('Requesting website scan list from server...');
        startScanTimer();

        // Step 1: Initialize scan (fetch list of IDs)
        activeScanXHR = $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_init_scan',
                security: aivaAdmin.nonce
            },
            success: function (response) {
                activeScanXHR = null;
                if (!isScanning) return;

                if (response.success && response.data) {
                    scanQueue = response.data.queue || [];
                    totalItemsCount = scanQueue.length;
                    processedItemsCount = 0;

                    addLog('Found ' + totalItemsCount + ' content items to scan (pages, posts, products, listings).', 'success');

                    if (totalItemsCount > 0) {
                        updateProgress();
                        processNextBatch();
                    } else {
                        // Queue empty, jump to business info indexing
                        indexBusinessInfo();
                    }
                } else {
                    addLog(response.data || 'Failed to initialize scan queue.', 'error');
                    resetScannerControls();
                }
            },
            error: function (xhr, status) {
                activeScanXHR = null;
                if (status === 'abort' || !isScanning) return;
                addLog('HTTP error initializing scan.', 'error');
                resetScannerControls();
            }
        });
    });

    // Stop Scan Button Handler
    $(document).on('click', '#aiva-stop-scan-modal-btn, #aiva-float-stop-scan', function (e) {
        e.preventDefault();

        isScanning = false;
        scanQueue = [];
        consecutiveErrors = 0;

        if (activeScanXHR && typeof activeScanXHR.abort === 'function') {
            try {
                activeScanXHR.abort();
            } catch (err) {}
            activeScanXHR = null;
        }

        stopScanTimer();
        addLog('Scan operation stopped by user.', 'error');
        $('#aiva-scan-status-text').html(svgWarning + ' Scanning stopped by user.');
        $('#aiva-modal-status-text').html(svgWarning + ' Scanning stopped by user.');
        $('#aiva-modal-live-badge').html(svgWarning + ' STOPPED').css({ 'background': 'rgba(239, 68, 68, 0.25)', 'color': '#f87171' });

        resetScannerControls();
    });

    var consecutiveErrors = 0;

    function extractCleanErrorMessage(err) {
        if (!err) return 'Unknown server error encountered.';

        var msg = '';
        if (typeof err === 'object') {
            if (err.error && err.error.message) {
                msg = err.error.message;
            } else if (err.message) {
                msg = err.message;
            } else {
                msg = JSON.stringify(err);
            }
        } else {
            msg = String(err);
        }

        // Try extracting embedded message inside JSON strings
        var match = msg.match(/"message":\s*"([^"]+)"/);
        if (match && match[1]) {
            msg = match[1];
        }

        // Replace escaped newlines with space, keep full exact message
        return msg.replace(/\\n/g, ' ').replace(/\s+/g, ' ').trim();
    }

    // Step 2: Batch processing loop
    function processNextBatch() {
        if (!isScanning) return;

        if (scanQueue.length === 0) {
            indexBusinessInfo();
            return;
        }

        // Pull a batch of 2 IDs per request for fast, reliable processing
        var batchSize = 2;
        var currentBatch = scanQueue.splice(0, batchSize);

        addLog('Indexing batch of ' + currentBatch.length + ' item(s)...');

        activeScanXHR = $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_scan_items',
                security: aivaAdmin.nonce,
                item_ids: currentBatch
            },
            success: function (response) {
                activeScanXHR = null;
                if (!isScanning) return;

                if (response.success) {
                    consecutiveErrors = 0; // Reset error counter on success
                    processedItemsCount += currentBatch.length;
                    updateProgress();

                    if (response.data && response.data.logs) {
                        response.data.logs.forEach(function (msg) {
                            addLog(msg, 'success');
                        });
                    }

                    // Continue scanning loop with smooth 350ms delay between batches
                    setTimeout(function() {
                        if (isScanning) processNextBatch();
                    }, 350);
                } else {
                    var rawErr = (response.data && response.data.error) ? response.data.error : (response.data || 'API Rate limit or server error');
                    var cleanMsg = extractCleanErrorMessage(rawErr);
                    var errStr = typeof rawErr === 'object' ? JSON.stringify(rawErr) : String(rawErr);

                    // Detect API Quota Exceeded / Rate Limit Errors
                    if (errStr.match(/QuotaFailure|RESOURCE_EXHAUSTED|QuotaExceeded|429|RateLimit|perday|free_tier/i)) {
                        addLog('Scanner Paused: ' + cleanMsg, 'error');
                        $('#aiva-scan-status-text').html(svgWarning + ' Paused: API Daily Quota Exceeded');
                        $('#aiva-modal-status-text').html(svgWarning + ' Scanner Paused: API Daily Quota Exceeded. Please wait for quota reset or upgrade API key.');
                        $('#aiva-modal-live-badge').html(svgWarning + ' QUOTA EXCEEDED').css({ 'background': 'rgba(239, 68, 68, 0.25)', 'color': '#f87171' });
                        resetScannerControls();
                        return; // STOP RETRIES ON DAILY QUOTA EXCEEDED!
                    }

                    consecutiveErrors++;
                    if (consecutiveErrors >= 3) {
                        addLog('Notice: Skipped item batch [' + currentBatch.join(', ') + '] after 3 attempts due to server response: ' + cleanMsg + '. Continuing scan...', 'error');
                        consecutiveErrors = 0;
                        processedItemsCount += currentBatch.length;
                        updateProgress();
                        setTimeout(function() {
                            if (isScanning) processNextBatch();
                        }, 400);
                        return;
                    }

                    addLog('Notice: ' + cleanMsg + ' (Attempt ' + consecutiveErrors + '/3). Retrying batch in 3 seconds...', 'error');
                    scanQueue = currentBatch.concat(scanQueue);
                    setTimeout(function() {
                        if (isScanning) processNextBatch();
                    }, 3000);
                }
            },
            error: function (xhr, status, error) {
                activeScanXHR = null;
                if (status === 'abort' || !isScanning) return;

                consecutiveErrors++;
                if (consecutiveErrors >= 3) {
                    addLog('Notice: Skipped item batch [' + currentBatch.join(', ') + '] after 3 network pauses. Continuing scan...', 'error');
                    consecutiveErrors = 0;
                    processedItemsCount += currentBatch.length;
                    updateProgress();
                    setTimeout(function() {
                        if (isScanning) processNextBatch();
                    }, 400);
                    return;
                }
                addLog('Network pause (Attempt ' + consecutiveErrors + '/3). Resuming scan in 3 seconds...', 'error');
                scanQueue = currentBatch.concat(scanQueue);
                setTimeout(function() {
                    if (isScanning) processNextBatch();
                }, 3000);
            }
        });
    }

    // Step 3: Index Business Profile Info
    function indexBusinessInfo() {
        if (!isScanning) return;
        addLog('Indexing manual Business Profile Info...');

        activeScanXHR = $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_scan_business_info',
                security: aivaAdmin.nonce
            },
            success: function (response) {
                activeScanXHR = null;
                if (!isScanning) return;

                if (response.success) {
                    addLog('Business Profile Info indexed successfully.', 'success');
                    if (response.data && response.data.total_chunks !== undefined) {
                        $('#aiva-chunks-count').text(response.data.total_chunks);
                    }
                } else {
                    addLog('Failed to index Business Profile Info: ' + (response.data || 'Unknown error'), 'error');
                }
                indexMenus();
            },
            error: function (xhr, status) {
                activeScanXHR = null;
                if (status === 'abort' || !isScanning) return;
                addLog('HTTP error indexing Business Info.', 'error');
                indexMenus();
            }
        });
    }

    // Step 4: Index Site Navigation Menus
    function indexMenus() {
        if (!isScanning) return;
        addLog('Indexing site navigation menus...');

        activeScanXHR = $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_scan_menus',
                security: aivaAdmin.nonce
            },
            success: function (response) {
                activeScanXHR = null;
                if (!isScanning) return;

                if (response.success) {
                    if (response.data && response.data.skipped) {
                        addLog('Site navigation menus indexing skipped (disabled in Privacy settings).');
                    } else {
                        addLog('Site navigation menus indexed successfully.', 'success');
                        if (response.data && response.data.logs) {
                            response.data.logs.forEach(function (msg) {
                                addLog(msg, 'success');
                            });
                        }
                    }
                } else {
                    var errorMsg = (response.data && response.data.error) ? response.data.error : (response.data || 'Unknown error');
                    addLog('Failed to index navigation menus: ' + errorMsg, 'error');
                    if (response.data && response.data.logs) {
                        response.data.logs.forEach(function (msg) {
                            addLog(msg, 'error');
                        });
                    }
                }
                finalizeScan();
            },
            error: function (xhr, status) {
                activeScanXHR = null;
                if (status === 'abort' || !isScanning) return;
                addLog('HTTP error indexing navigation menus.', 'error');
                finalizeScan();
            }
        });
    }

    // Step 5: Finalize Scan
    function finalizeScan() {
        if (!isScanning) return;
        addLog('Scanning completed successfully!', 'success');
        $('#aiva-scan-status-text').text('Scanning complete!');
        $('#aiva-modal-status-text').text('Scanning complete! All items indexed successfully.');
        $('#aiva-scan-progress-bar').css('background', '#10b981');
        $('#aiva-modal-progress-bar').css('background', '#10b981');
        $('#aiva-modal-live-badge').html(svgSuccess + ' COMPLETE').css({ 'background': 'rgba(16, 185, 129, 0.15)', 'color': '#4ade80' });
        stopScanTimer();
        resetScannerControls();
    }

    function resetScannerControls() {
        isScanning = false;
        stopScanTimer();
        $('#aiva-stop-scan-modal-btn, #aiva-float-stop-scan').hide();
        $('#aiva-floating-scan-bar').fadeOut(500);
        $('#aiva-start-scan').prop('disabled', false).html(svgBtnUpdate + ' Scan & Index Website');
        $('#aiva-clear-index').prop('disabled', false);
    }

    // 3. Custom Wipe Index Modal & Confirmation Handler
    $(document).on('click', '#aiva-clear-index, .aiva-btn-wipe', function (e) {
        e.preventDefault();
        $('#aiva-wipe-feedback').hide().removeClass('success error info').text('');
        $('#aiva-confirm-wipe-btn').prop('disabled', false).html(svgBtnTrash + ' Yes, Wipe Index');
        $('#aiva-wipe-modal-overlay').stop(true, true).css('display', 'flex').hide().fadeIn(200);
    });

    $(document).on('click', '.aiva-wipe-cancel-btn', function (e) {
        e.preventDefault();
        $('#aiva-wipe-modal-overlay').fadeOut(150);
    });

    // Close modal on escape key or backdrop click
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#aiva-wipe-modal-overlay').is(':visible')) {
            $('#aiva-wipe-modal-overlay').fadeOut(150);
        }
    });

    $(document).on('click', '#aiva-wipe-modal-overlay', function (e) {
        if ($(e.target).is('#aiva-wipe-modal-overlay')) {
            $('#aiva-wipe-modal-overlay').fadeOut(150);
        }
    });

    $(document).on('click', '#aiva-confirm-wipe-btn', function (e) {
        e.preventDefault();
        var btn = $(this);
        var feedback = $('#aiva-wipe-feedback');

        btn.prop('disabled', true).html('<svg style="width:14px;height:14px;margin-right:6px;animation:aiva-spin 0.8s linear infinite;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg> Wiping Index...');
        feedback.removeClass('success error').addClass('info').css('display', 'flex').html('<svg style="width:16px;height:16px;margin-right:8px;flex-shrink:0;animation:aiva-spin 0.8s linear infinite;color:#2dd4bf;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg> Wiping AI vector index & database embeddings...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_clear_index',
                security: aivaAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#aiva-chunks-count').text('0');
                    feedback.removeClass('info error').addClass('success').html(svgSuccess + ' Knowledge base vector index cleared successfully.');
                    setTimeout(function () {
                        $('#aiva-wipe-modal-overlay').fadeOut(200);
                        btn.prop('disabled', false).html(svgBtnTrash + ' Yes, Wipe Index');
                    }, 1200);
                } else {
                    feedback.removeClass('info success').addClass('error').html(svgError + ' Wipe failed: ' + aivaEsc(response.data || 'Unknown error'));
                    btn.prop('disabled', false).html(svgBtnTrash + ' Yes, Wipe Index');
                }
            },
            error: function () {
                feedback.removeClass('info success').addClass('error').html(svgError + ' Server communication error while wiping database index.');
                btn.prop('disabled', false).html(svgBtnTrash + ' Yes, Wipe Index');
            }
        });
    });

    // 4. API Key Test connection handlers
    $('#aiva-test-gemini').on('click', function () {
        var btn = $(this);
        var input = $('#aiva_gemini_api_key');
        var feedback = $('#aiva-gemini-test-result');
        var keyVal = input.val().trim();

        btn.prop('disabled', true).text('Testing...');
        feedback.removeClass('success error').addClass('info').text('Connecting...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_test_gemini_key',
                security: aivaAdmin.nonce,
                api_key: keyVal
            },
            success: function (response) {
                if (response.success) {
                    feedback.removeClass('info error').addClass('success').html(svgSuccess + ' ' + aivaEsc(response.data));
                } else {
                    feedback.removeClass('info success').addClass('error').html(svgError + ' Error: ' + aivaEsc(response.data));
                }
                btn.prop('disabled', false).text('Test connection');
            },
            error: function () {
                feedback.removeClass('info success').addClass('error').html(svgError + ' HTTP Error testing key.');
                btn.prop('disabled', false).text('Test connection');
            }
        });
    });

    $('#aiva-test-openai').on('click', function () {
        var btn = $(this);
        var input = $('#aiva_openai_api_key');
        var feedback = $('#aiva-openai-test-result');
        var keyVal = input.val().trim();

        btn.prop('disabled', true).text('Testing...');
        feedback.removeClass('success error').addClass('info').text('Connecting...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_test_openai_key',
                security: aivaAdmin.nonce,
                api_key: keyVal
            },
            success: function (response) {
                if (response.success) {
                    feedback.removeClass('info error').addClass('success').html(svgSuccess + ' ' + aivaEsc(response.data));
                } else {
                    feedback.removeClass('info success').addClass('error').html(svgError + ' Error: ' + aivaEsc(response.data));
                }
                btn.prop('disabled', false).text('Test connection');
            },
            error: function () {
                feedback.removeClass('info success').addClass('error').html(svgError + ' HTTP Error testing key.');
                btn.prop('disabled', false).text('Test connection');
            }
        });
    });

    $('#aiva-test-anthropic').on('click', function () {
        var btn = $(this);
        var input = $('#aiva_anthropic_api_key');
        var feedback = $('#aiva-anthropic-test-result');
        var keyVal = input.val().trim();

        btn.prop('disabled', true).text('Testing...');
        feedback.removeClass('success error').addClass('info').text('Connecting...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_test_anthropic_key',
                security: aivaAdmin.nonce,
                api_key: keyVal
            },
            success: function (response) {
                if (response.success) {
                    feedback.removeClass('info error').addClass('success').html(svgSuccess + ' ' + aivaEsc(response.data));
                } else {
                    feedback.removeClass('info success').addClass('error').html(svgError + ' Error: ' + aivaEsc(response.data));
                }
                btn.prop('disabled', false).text('Test connection');
            },
            error: function () {
                feedback.removeClass('info success').addClass('error').html(svgError + ' HTTP Error testing key.');
                btn.prop('disabled', false).text('Test connection');
            }
        });
    });

    $('#aiva-test-elevenlabs').on('click', function () {
        var btn = $(this);
        var input = $('#aiva_elevenlabs_api_key');
        var feedback = $('#aiva-elevenlabs-test-result');
        var keyVal = input.val().trim();

        btn.prop('disabled', true).text('Testing...');
        feedback.removeClass('success error').addClass('info').text('Connecting...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_test_elevenlabs_key',
                security: aivaAdmin.nonce,
                api_key: keyVal
            },
            success: function (response) {
                if (response.success) {
                    feedback.removeClass('info error').addClass('success').html(svgSuccess + ' ' + aivaEsc(response.data));
                } else {
                    feedback.removeClass('info success').addClass('error').html(svgError + ' Error: ' + aivaEsc(response.data));
                }
                btn.prop('disabled', false).text('Test Connection');
            },
            error: function () {
                feedback.removeClass('info success').addClass('error').html(svgError + ' HTTP Error testing key.');
                btn.prop('disabled', false).text('Test Connection');
            }
        });
    });



    // Dynamic key input change listener to enable/disable Test Connection buttons
    $(document).on('input change keyup paste', '#aiva_gemini_api_key, #aiva_openai_api_key, #aiva_anthropic_api_key, #aiva_elevenlabs_api_key', function () {
        var keyVal = $(this).val().trim();
        var id = $(this).attr('id');
        var btnId = '#aiva-test-' + id.replace('aiva_', '').replace('_api_key', '');

        if (keyVal.length > 0) {
            $(btnId).prop('disabled', false);
        } else {
            $(btnId).prop('disabled', true);
        }
    });

    // Voice Preset Selector sync handler
    $(document).on('change', '#aiva_voice_preset_select', function () {
        var val = $(this).val();
        if (val !== 'custom') {
            $('#aiva_elevenlabs_voice_id').val(val);
        }
    });

    // Listen Voice Sample Button Click Handler
    $(document).on('click', '#aiva-test-voice-sample', function () {
        var btn = $(this);
        var key = $('#aiva_elevenlabs_api_key').val().trim();
        var voiceId = $('#aiva_elevenlabs_voice_id').val().trim();
        var feedback = $('#aiva-voice-sample-feedback');

        if (!key) {
            feedback.removeClass('success').addClass('error info').css('display', 'block').text('Please enter an ElevenLabs API key first.');
            return;
        }
        if (!voiceId) {
            feedback.removeClass('success').addClass('error info').css('display', 'block').text('Please select or enter a Voice ID first.');
            return;
        }

        btn.prop('disabled', true).text('Generating Sample...');
        feedback.removeClass('error success').addClass('info').css('display', 'block').text('Connecting to ElevenLabs API and generating voice audio sample...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_test_elevenlabs_voice',
                security: aivaAdmin.nonce,
                key: key,
                voice_id: voiceId
            },
            success: function (res) {
                btn.prop('disabled', false).html(svgBtnPlay + ' Listen Voice Sample');
                if (res.success && res.data.audio_url) {
                    feedback.removeClass('error info').addClass('success').html(svgSuccess + ' Voice generated successfully! Playing sample audio now...');
                    var sampleAudio = new Audio(res.data.audio_url);
                    sampleAudio.play().catch(function (e) {
                        console.error('Playback error:', e);
                    });
                } else {
                    var errMsg = res.data ? res.data : 'Error generating sample voice.';
                    feedback.removeClass('success info').addClass('error').html(svgError + ' ' + aivaEsc(errMsg));
                }
            },
            error: function () {
                btn.prop('disabled', false).html(svgBtnPlay + ' Listen Voice Sample');
                feedback.removeClass('success info').addClass('error').html(svgError + ' HTTP request error testing voice.');
            }
        });
    });

    // Purge Audio Cache Button Handler
    $(document).on('click', '#aiva-clear-audio-cache', function () {
        var btn = $(this);
        var feedback = $('#aiva-voice-sample-feedback');

        btn.prop('disabled', true).text('Purging Cache...');
        feedback.removeClass('error success').addClass('info').css('display', 'block').text('Clearing cached audio files...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_clear_audio_cache',
                security: aivaAdmin.nonce
            },
            success: function (res) {
                btn.prop('disabled', false).html(svgBtnTrash + ' Purge Audio Cache');
                if (res.success) {
                    feedback.removeClass('error info').addClass('success').html(svgSuccess + ' Audio cache cleared successfully! Fresh audio will be generated on your next query.');
                } else {
                    feedback.removeClass('success info').addClass('error').html(svgError + ' ' + res.data);
                }
            },
            error: function () {
                btn.prop('disabled', false).html(svgBtnTrash + ' Purge Audio Cache');
                feedback.removeClass('success info').addClass('error').html(svgError + ' HTTP error purging audio cache.');
            }
        });
    });

    // 5. Dismiss Admin Review Notice Handler
    $(document).on('click', '.aiva-review-notice .notice-dismiss, .aiva-dismiss-review', function (e) {
        var dismissType = $(this).data('dismiss') || 'permanent';
        if (dismissType === 'temporary') {
            e.preventDefault();
        }

        // Hide notice visually
        $('.aiva-review-notice').slideUp();

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_dismiss_review_notice',
                security: aivaAdmin.nonce,
                dismiss_type: dismissType
            }
        });
    });

    // 6. Remove Saved API Key Checkbox Handler
    $(document).on('change', '.aiva-remove-key', function () {
        var targetInputId = $(this).data('target');
        var targetInput = $('#' + targetInputId);

        if ($(this).is(':checked')) {
            $(this).data('backup-val', targetInput.val());
            targetInput.val('').trigger('change');
        } else {
            var backupVal = $(this).data('backup-val') || '';
            targetInput.val(backupVal).trigger('change');
        }
    });

    // 7. Engine chip selection click handler
    $(document).on('click', '.aiva-engine-chip', function (e) {
        if ($(e.target).is('input, button, a, label, span.dashicons-external')) {
            return;
        }
        var radio = $(this).find('input[type="radio"]');
        radio.prop('checked', true).trigger('change');
    });

    $(document).on('change', 'input[name="aiva_active_llm_engine"]', function () {
        // Reset all chips
        $('.aiva-engine-chip').removeClass('active gemini openai anthropic');

        // Setup active chip
        var selectedChip = $(this).closest('.aiva-engine-chip');
        var engineType = selectedChip.data('engine');
        selectedChip.addClass('active ' + engineType);
    });

    // 8. Trigger chip selection click handler
    $(document).on('click', '.aiva-trigger-chip', function (e) {
        if ($(e.target).is('input')) return;
        var radio = $(this).find('input[type="radio"]');
        radio.prop('checked', true).trigger('change');
    });

    $(document).on('change', 'input[name="aiva_widget_trigger_style"]', function () {
        $('.aiva-trigger-chip').removeClass('active');
        $(this).closest('.aiva-trigger-chip').addClass('active');
    });

    // 9. Playback Mode card click handler
    $(document).on('click', '.aiva-mode-card', function (e) {
        if ($(e.target).is('input')) return;
        var radio = $(this).find('input[type="radio"]');
        radio.prop('checked', true).trigger('change');
    });

    $(document).on('change', 'input[name="aiva_tts_engine"]', function () {
        $('.aiva-mode-card').removeClass('active');
        $(this).closest('.aiva-mode-card').addClass('active');
        if ($(this).val() === 'browser') {
            $('#aiva-elevenlabs-settings-wrapper').slideUp();
        } else {
            $('#aiva-elevenlabs-settings-wrapper').slideDown();
        }
    });

    // 10. Voice Preset Dropdown & Custom Voice ID Sync
    $(document).on('change', '#aiva_voice_preset_select', function () {
        var selectedVal = $(this).val();
        if (selectedVal !== 'custom') {
            $('#aiva_elevenlabs_voice_id').val(selectedVal).trigger('change');
        }
    });

    $(document).on('input change', '#aiva_elevenlabs_voice_id', function () {
        var currentVal = $(this).val().trim();
        if ($('#aiva_voice_preset_select option[value="' + currentVal + '"]').length > 0) {
            $('#aiva_voice_preset_select').val(currentVal);
        } else {
            $('#aiva_voice_preset_select').val('custom');
        }
    });

    // 10. Enable/Disable test connection buttons dynamically based on input values
    function toggleTestButton(inputSelector, buttonSelector) {
        $(inputSelector).on('input change', function () {
            var val = $(this).val().trim();
            $(buttonSelector).prop('disabled', val.length === 0);
        });
    }

    toggleTestButton('#aiva_gemini_api_key', '#aiva-test-gemini');
    toggleTestButton('#aiva_openai_api_key', '#aiva-test-openai');
    toggleTestButton('#aiva_anthropic_api_key', '#aiva-test-anthropic');

    // 11. Indexed Knowledge Base Data Inspector Module
    var inspectorCurrentPage = 1;

    $(document).on('click', '#aiva-open-data-inspector', function (e) {
        e.preventDefault();
        $('#aiva-inspector-modal-overlay').fadeIn(200);
        loadInspectorData(1);
    });

    $(document).on('click', '#aiva-close-inspector-modal', function (e) {
        e.preventDefault();
        $('#aiva-inspector-modal-overlay').fadeOut(200);
    });

    function loadInspectorData(page = 1) {
        inspectorCurrentPage = page;
        var searchVal = $('#aiva-inspector-search').val().trim();
        var typeVal = $('#aiva-inspector-type-filter').val();

        var tbody = $('#aiva-inspector-tbody');
        tbody.html('<tr><td colspan="6" style="text-align: center; padding: 25px; color: #64748b;"><span class="dashicons dashicons-update spin"></span> Loading database records...</td></tr>');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_get_indexed_data',
                security: aivaAdmin.nonce,
                search: searchVal,
                type: typeVal,
                page: page
            },
            success: function (res) {
                if (res.success && res.data) {
                    var items = res.data.items || [];
                    var totalCount = res.data.total_count || 0;
                    var totalPages = res.data.total_pages || 1;

                    $('#aiva-inspector-count-info').text('Showing ' + items.length + ' of ' + totalCount + ' records');
                    $('#aiva-inspector-badge-count').text(totalCount);
                    $('#aiva-inspector-page-indicator').text('Page ' + page + ' of ' + Math.max(1, totalPages));
                    $('#aiva-inspector-prev-page').prop('disabled', page <= 1);
                    $('#aiva-inspector-next-page').prop('disabled', page >= totalPages);

                    if (items.length === 0) {
                        tbody.html('<tr><td colspan="6" style="text-align: center; padding: 25px; color: #94a3b8;">No matching indexed database records found. Click "Scan & Index Website" to index content.</td></tr>');
                        return;
                    }

                    var html = '';
                    items.forEach(function (item) {
                        var tagClass = 'tag-' + item.content_type;
                        var partHtml = item.part_info ? ' <span style="font-size: 11px; color: #64748b; font-weight: 500;">' + aivaEsc(item.part_info) + '</span>' : '';
                        var linkHtml = item.permalink ? '<a href="' + aivaEsc(item.permalink) + '" target="_blank" rel="noopener" style="text-decoration:none; font-weight:600; color:#0d9488;">' + aivaEsc(item.title) + partHtml + ' <span class="dashicons dashicons-external" style="font-size:12px; width:12px; height:12px;"></span></a>' : '<strong>' + aivaEsc(item.title) + '</strong>' + partHtml;

                        html += '<tr id="aiva-row-' + parseInt(item.id, 10) + '">';
                        html += '<td><strong style="color:#64748b; font-size:12px;">#' + item.id + '</strong></td>';
                        html += '<td><span class="aiva-type-tag ' + tagClass + '">' + item.content_type + '</span></td>';
                        html += '<td>' + linkHtml + '</td>';
                        html += '<td><span class="aiva-snippet-text">' + item.snippet + '</span></td>';
                        html += '<td><span class="aiva-hash-code">' + item.hash + '...</span></td>';
                        html += '<td style="text-align: center;"><button type="button" class="aiva-delete-chunk-btn aiva-delete-record" data-id="' + parseInt(item.id, 10) + '" title="Delete this single vector chunk"><span class="dashicons dashicons-trash"></span></button></td>';
                        html += '</tr>';
                    });

                    tbody.html(html);
                } else {
                    tbody.html('<tr><td colspan="6" style="text-align: center; padding: 20px; color: #f87171;">Error loading database records.</td></tr>');
                }
            },
            error: function () {
                tbody.html('<tr><td colspan="6" style="text-align: center; padding: 20px; color: #f87171;">HTTP Connection error fetching database records.</td></tr>');
            }
        });
    }

    // Load initial inspector data on page load if inspector card is present
    if ($('#aiva-inspector-tbody').length) {
        loadInspectorData(1);
    }

    // Refresh button
    $('#aiva-refresh-inspector').on('click', function () {
        loadInspectorData(1);
    });

    // Live search typing with delay
    var searchTimer = null;
    $('#aiva-inspector-search').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            loadInspectorData(1);
        }, 300);
    });

    // Type filter dropdown change
    $('#aiva-inspector-type-filter').on('change', function () {
        loadInspectorData(1);
    });

    // Pagination buttons
    $('#aiva-inspector-prev-page').on('click', function () {
        if (inspectorCurrentPage > 1) {
            loadInspectorData(inspectorCurrentPage - 1);
        }
    });

    $('#aiva-inspector-next-page').on('click', function () {
        loadInspectorData(inspectorCurrentPage + 1);
    });

    // Delete single record
    $(document).on('click', '.aiva-delete-record', function () {
        var recordId = $(this).data('id');
        if (!confirm('Are you sure you want to delete this single database chunk record?')) {
            return;
        }

        var row = $('#aiva-row-' + recordId);
        row.css('opacity', '0.4');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_delete_indexed_record',
                security: aivaAdmin.nonce,
                record_id: recordId
            },
            success: function (res) {
                if (res.success) {
                    row.fadeOut(300, function () { $(this).remove(); });
                    if (res.data && res.data.total_chunks !== undefined) {
                        $('#aiva-chunks-count').text(res.data.total_chunks);
                    }
                } else {
                    alert('Failed to delete record: ' + (res.data || 'Unknown error'));
                    row.css('opacity', '1');
                }
            },
            error: function () {
                alert('HTTP Error deleting record.');
                row.css('opacity', '1');
            }
        });
    });

    // 14. Meta Box Single Item Indexing Handler on Edit Post/Listing Page
    $(document).on('click', '#aiva-single-index-btn', function (e) {
        e.preventDefault();
        var btn = $(this);
        var postId = btn.data('post-id');
        var feedback = $('#aiva-single-index-feedback');

        btn.prop('disabled', true).html(svgBtnUpdate + ' Indexing...');
        feedback.css({ 'display': 'block', 'background': '#f1f5f9', 'color': '#0f766e', 'border': '1px solid #cbd5e1' }).text('Generating vector embeddings...');

        $.ajax({
            url: aivaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_index_single_post',
                security: aivaAdmin.nonce,
                post_id: postId
            },
            success: function (res) {
                btn.prop('disabled', false).html(svgBtnUpdate + ' Index / Re-index Now');
                if (res.success) {
                    feedback.css({ 'background': '#dcfce7', 'color': '#15803d', 'border': '1px solid #bbf7d0' }).html(svgSuccess + ' ' + aivaEsc(res.data.message));
                } else {
                    var errMsg = extractCleanErrorMessage(res.data);
                    feedback.css({ 'background': '#fef2f2', 'color': '#991b1b', 'border': '1px solid #fecaca' }).html(svgError + ' ' + aivaEsc(errMsg));
                }
            },
            error: function () {
                btn.prop('disabled', false).html(svgBtnUpdate + ' Index / Re-index Now');
                feedback.css({ 'background': '#fef2f2', 'color': '#991b1b', 'border': '1px solid #fecaca' }).html(svgError + ' HTTP error connecting to server.');
            }
        });
    });
});

// Visitor Languages: select all / clear helpers.
jQuery(function ($) {
    $(document).on('click', '.aiva-lang-select-all', function (e) {
        e.preventDefault();
        $('.aiva-lang-admin-grid input[type="checkbox"]').prop('checked', true);
    });
    $(document).on('click', '.aiva-lang-select-none', function (e) {
        e.preventDefault();
        $('.aiva-lang-admin-grid input[type="checkbox"]').prop('checked', false);
    });
});
