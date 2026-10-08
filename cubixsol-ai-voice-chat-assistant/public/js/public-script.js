/**
 * Frontend Public JavaScript Controller for Cubixsol AI Assistant Widget.
 * Handles Web Speech STT/TTS, ElevenLabs audio playback, chat UI messaging, markdown formatting, and product cards.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

jQuery(document).ready(function ($) {
    var aivaI18n = (window.aivaPublic && aivaPublic.i18n) ? aivaPublic.i18n : {};

    // Warning icon used in error bubbles (was referenced but never defined, which broke every error message).
    var warningSvgIcon = '<svg class="aiva-svg-status warning" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>';

    // Escape text before inserting it as HTML (AI answers, product data and user text are never trusted as markup).
    function escapeHtml(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Only allow http(s) and same-site relative URLs (and audio data URIs where needed).
    function safeUrl(url, allowDataAudio) {
        url = String(url || '').trim();
        if (/^https?:\/\//i.test(url) || /^\/(?!\/)/.test(url)) {
            return url;
        }
        if (allowDataAudio && /^data:audio\/(mpeg|mp3);base64,/i.test(url)) {
            return url;
        }
        return '';
    }

    function debugLog() {
        if (window.aivaPublic && aivaPublic.debug && window.console) {
            console.log.apply(console, arguments);
        }
    }

    // Open a page in a new tab. Browsers block pop-ups that are not started by a click,
    // so when that happens we show a clear link button in the chat instead.
    function safeOpen(url, label) {
        url = safeUrl(url);
        if (!url) return;
        var win = window.open(url, '_blank', 'noopener');
        if (!win) {
            var text = label || aivaI18n.openPage || 'Open page';
            var $link = $('<a class="aiva-link-badge aiva-open-fallback" target="_blank" rel="noopener"></a>')
                .attr('href', url)
                .text(text + ' \u2197');
            var $wrap = $('<div class="aiva-msg bot animate-pop"><div class="aiva-links-container" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;"></div></div>');
            $wrap.find('.aiva-links-container').append($link);
            $('#aiva-chat-messages').append($wrap);
            scrollToBottom();
        }
    }
    // Avoid overlap with popular WhatsApp widgets (OmniChat, Click to Chat, Join.chat, etc.)
    function checkWhatsAppOverlap() {
        if ($('#aiva-launcher').hasClass('aiva-pos-bottom-left')) {
            $('body').removeClass('aiva-shifted-up');
            return;
        }
        var hasWhatsApp = $('#omnichat-widget-container.omnichat-pos-bottom-right').length > 0 ||
            $('.ht-ctc-chat').length > 0 ||
            $('.joinchat').length > 0;
        if (hasWhatsApp) {
            $('body').addClass('aiva-shifted-up');
        } else {
            $('body').removeClass('aiva-shifted-up');
        }
    }
    checkWhatsAppOverlap();
    $(window).on('load', checkWhatsAppOverlap);
    setTimeout(checkWhatsAppOverlap, 500);
    setTimeout(checkWhatsAppOverlap, 1500);
    setTimeout(checkWhatsAppOverlap, 3000);

    // 1. Launcher and Widget Toggles
    $('#aiva-launcher').on('click', function () {
        $('#aiva-widget').fadeToggle(300);
        scrollToBottom();
    });

    $('#aiva-close-btn').on('click', function () {
        $('#aiva-widget').fadeOut(250);
        stopSpeaking();
    });

    // 2. Chat Variables and State Management
    var recognition = null;
    var isListening = false;
    var isSpeaking = false;
    var isThinking = false;
    var activeUtterance = null;
    var activeAudio = null;
    var activeReplayBtn = null;

    function resetReplayButtons() {
        if (activeReplayBtn && activeReplayBtn.length) {
            activeReplayBtn.removeClass('playing');
            activeReplayBtn.find('.dashicons').attr('class', 'dashicons dashicons-controls-play');
            activeReplayBtn.find('.aiva-btn-text').text('Listen');
            activeReplayBtn = null;
        }
        $('.aiva-replay-btn').removeClass('playing').each(function () {
            $(this).find('.dashicons').attr('class', 'dashicons dashicons-controls-play');
            $(this).find('.aiva-btn-text').text('Listen');
        });
        $('.aiva-audio-controls-row').removeClass('is-playing');
    }

    function scrollToBottom() {
        var chatBody = $('#aiva-chat-messages');
        chatBody.scrollTop(chatBody[0].scrollHeight);
    }

    // Cache matched products for redirection
    var pendingProducts = [];

    // Helper to format simple markdown elements (bolding and bullet lists) into HTML
    function formatMarkdown(text) {
        if (!text) return '';
        // Replace **bold** with <strong>bold</strong>
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Replace * separators with clean bullet points and breaks
        text = text.replace(/\s\*\s/g, '<br>• ');
        text = text.replace(/\*/g, '');
        return text;
    }

    // Append Message to Log
    function appendMessage(text, sender, products = [], links = [], ttsUrl = '') {
        var msgClass = sender === 'user' ? 'user' : 'bot';
        // isHtml is only true for internal status messages built by this script (never for server/user text).
        var isHtml = arguments.length > 5 && arguments[5] === true;
        var safeText = isHtml ? text : escapeHtml(text);
        var formattedText = (sender === 'bot' && !isHtml) ? formatMarkdown(safeText) : safeText;
        var bubbleHTML = '<div class="aiva-bubble" dir="auto">' + formattedText + '</div>';

        if (sender === 'bot' && !isHtml) {
            var cleanTts = safeUrl(ttsUrl, true);
            var ttsAttr = cleanTts ? ' data-tts-url="' + escapeHtml(cleanTts) + '"' : '';
            bubbleHTML += '<div class="aiva-audio-controls-row">' +
                '<button type="button" class="aiva-replay-btn"' + ttsAttr + ' title="Listen audio response">' +
                '<span class="dashicons dashicons-controls-play"></span> ' +
                '<span class="aiva-btn-text">Listen</span>' +
                '<span class="aiva-equalizer-bars" title="Voice audio wavelength">' +
                '<span class="bar bar-1"></span>' +
                '<span class="bar bar-2"></span>' +
                '<span class="bar bar-3"></span>' +
                '<span class="bar bar-4"></span>' +
                '<span class="bar bar-5"></span>' +
                '</span>' +
                '</button>' +
                '</div>';
        }

        var productsHTML = '';
        if (products && products.length > 0) {
            productsHTML += '<div class="aiva-products-container">';
            products.forEach(function (prod) {
                var prodUrl = safeUrl(prod.url);
                var prodImg = safeUrl(prod.image);
                productsHTML += '<div class="aiva-product-card">' +
                    (prodImg ? '<img class="aiva-prod-thumb" src="' + escapeHtml(prodImg) + '" alt="' + escapeHtml(prod.name) + '" loading="lazy">' : '') +
                    '<div class="aiva-prod-info">' +
                    '<h4>' + escapeHtml(prod.name) + '</h4>' +
                    '<span class="aiva-prod-price">' + escapeHtml(prod.price) + '</span>' +
                    (prodUrl ? '<a class="aiva-prod-link" href="' + escapeHtml(prodUrl) + '" target="_blank" rel="noopener">' + escapeHtml(aivaI18n.viewProduct || 'View Product') + ' <span class="dashicons dashicons-arrow-right-alt2"></span></a>' : '') +
                    '</div>' +
                    '</div>';
            });
            productsHTML += '</div>';
        }

        var linksHTML = '';
        if (links && links.length > 0) {
            linksHTML += '<div class="aiva-links-container" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;">';
            links.forEach(function (link) {
                var linkUrl = safeUrl(link.url);
                if (!linkUrl) return;
                linksHTML += '<a class="aiva-link-badge" href="' + escapeHtml(linkUrl) + '" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 3px; background: rgba(13, 148, 136, 0.1); color: #0d9488; text-decoration: none; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; border: 1px solid rgba(13, 148, 136, 0.2); transition: all 0.2s ease;">' +
                    '<span class="dashicons dashicons-admin-links" style="font-size: 12px; width: 12px; height: 12px;"></span> ' + escapeHtml(link.title) + '</a>';
            });
            linksHTML += '</div>';
        }

        var messageElement = $('<div class="aiva-msg ' + msgClass + ' animate-pop">' + bubbleHTML + productsHTML + linksHTML + '</div>');
        $('#aiva-chat-messages').append(messageElement);
        scrollToBottom();
    }

    // Set UI State Machine classes
    function setWidgetState(state) {
        var container = $('#aiva-widget');
        container.removeClass('listening thinking speaking idle');

        if (state === 'listening') {
            container.addClass('listening');
            $('#aiva-agent-status').text('Listening... Speak now.');
            isListening = true;
        } else if (state === 'thinking') {
            container.addClass('thinking');
            $('#aiva-agent-status').text('Processing speech query...');
            isThinking = true;
        } else if (state === 'speaking') {
            container.addClass('speaking');
            $('#aiva-agent-status').text('Speaking... Tap mic to stop.');
            isSpeaking = true;
            $('#aiva-stop-speech').show();
        } else {
            container.addClass('idle');
            $('#aiva-agent-status').text('Click microphone to speak.');
            $('#aiva-stop-speech').hide();
            isListening = false;
            isSpeaking = false;
            isThinking = false;
        }
    }

    // 3. Setup Web Speech Recognition
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.lang = $('#aiva-lang-select').val() || 'en-US';

        recognition.onstart = function () {
            setWidgetState('listening');
        };

        recognition.onresult = function (event) {
            var transcript = event.results[0][0].transcript;
            if (transcript) {
                handleQueryInput(transcript);
            }
        };

        recognition.onerror = function (event) {
            debugLog('Speech Recognition Error:', event.error);
            if (event.error === 'language-not-supported') {
                appendMessage(escapeHtml(aivaI18n.noSpeechLang || 'Voice input may not support this language in your browser. You can type your question instead.'), 'bot', [], [], '', true);
            } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
                appendMessage('Sorry, I had trouble hearing you. Please try again.', 'bot');
            }
            setWidgetState('idle');
        };

        recognition.onend = function () {
            if (!isThinking) {
                setWidgetState('idle');
            }
        };
    } else {
        // Fallback: Web Speech not supported
        $('#aiva-mic-trigger').prop('disabled', true).css('opacity', '0.5');
        $('#aiva-agent-status').text('Speech Recognition unsupported. Use chat input.');
    }

    var conversationHistory = [];

    // 4. Send Voice Transcript to WordPress AJAX API
    var aivaNonce = (window.aivaPublic && aivaPublic.nonce) ? aivaPublic.nonce : '';

    // Pages served from a full-page cache can carry an expired nonce: fetch a fresh one and retry once.
    function refreshNonceAndRetry(queryText) {
        $.post(aivaPublic.ajax_url, { action: 'aiva_refresh_nonce' })
            .done(function (res) {
                if (res && res.success && res.data && res.data.nonce) {
                    aivaNonce = res.data.nonce;
                    sendQueryToServer(queryText, true);
                } else {
                    appendMessage(aivaI18n.connectError || 'Sorry, I could not connect to the server.', 'bot');
                    setWidgetState('idle');
                }
            })
            .fail(function () {
                appendMessage(aivaI18n.connectError || 'Sorry, I could not connect to the server.', 'bot');
                setWidgetState('idle');
            });
    }

    function sendQueryToServer(queryText, isRetry) {
        setWidgetState('thinking');

        // Add user turn to conversation history (only once, not again on a retry)
        if (!isRetry) {
            conversationHistory.push({ role: 'user', content: queryText });
            if (conversationHistory.length > 6) {
                conversationHistory = conversationHistory.slice(-6);
            }
        }

        $.ajax({
            url: aivaPublic.ajax_url,
            type: 'POST',
            data: {
                action: 'aiva_query_agent',
                security: aivaNonce,
                query: queryText,
                history: JSON.stringify(conversationHistory),
                language: $('#aiva-lang-select').val() || 'en-US'
            },
            success: function (response) {
                if (response.success && response.data) {
                    var answer = response.data.answer;
                    var products = response.data.products || [];
                    var links = response.data.suggested_links || [];
                    var redirectUrl = response.data.redirect_url || '';
                    var ttsUrl = response.data.tts_url || '';

                    // Add assistant turn to conversation history
                    conversationHistory.push({ role: 'assistant', content: answer });
                    if (conversationHistory.length > 6) {
                        conversationHistory = conversationHistory.slice(-6);
                    }

                    // Cache pending products
                    pendingProducts = products;

                    appendMessage(answer, 'bot', products, links, ttsUrl);
                    renderQuickReplies(response.data.suggestions || []);

                    if (redirectUrl) {
                        safeOpen(redirectUrl);
                    }

                    debugLog('[AIVA] ElevenLabs audio:', ttsUrl ? 'yes' : 'no (browser speech)');

                    speakText(answer, ttsUrl);
                } else {
                    var rawErr = response.data || 'Could not fetch a response.';
                    var cleanMsg = extractCleanErrorMessage(rawErr);
                    appendMessage(cleanMsg, 'bot', [], [], '', true);
                    stopSpeaking();
                    setWidgetState('idle');
                }
            },
            error: function (xhr) {
                var res = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                if (!isRetry && xhr && xhr.status === 403 && res && res.data && res.data.code === 'invalid_nonce') {
                    refreshNonceAndRetry(queryText);
                    return;
                }
                if (res && res.data) {
                    appendMessage(extractCleanErrorMessage(res.data), 'bot', [], [], '', true);
                } else {
                    appendMessage(aivaI18n.connectError || 'Sorry, I could not connect to the server.', 'bot');
                }
                stopSpeaking();
                setWidgetState('idle');
            }
        });
    }

    // Helper to extract clean error message from raw API JSON response
    function extractCleanErrorMessage(err) {
        if (!err) return escapeHtml(aivaI18n.genericError || 'An unexpected error occurred.');
        var message = '';

        if (typeof err === 'string') {
            if (err.indexOf('{') !== -1 && err.indexOf('}') !== -1) {
                try {
                    var jsonStr = err.substring(err.indexOf('{'), err.lastIndexOf('}') + 1);
                    var parsed = JSON.parse(jsonStr);
                    if (parsed && parsed.error && parsed.error.message) {
                        message = parsed.error.message;
                    }
                } catch (e) { }
            }
            if (!message) message = err;
        } else if (typeof err === 'object') {
            if (err.message) message = err.message;
            else if (err.error && typeof err.error === 'string') message = err.error;
            else if (err.error && err.error.message) message = err.error.message;
            else message = JSON.stringify(err);
        } else {
            message = String(err);
        }

        return formatErrorMessage(message);
    }

    function formatErrorMessage(rawMessage) {
        var message = rawMessage || '';

        // Clean out raw technical Google/OpenAI URLs if present
        if (message.indexOf('https://') !== -1) {
            message = message.replace(/https?:\/\/[^\s]+/g, '').trim();
        }
        // Remove trailing bullet points or raw technical metrics
        if (message.indexOf('• Quota exceeded') !== -1) {
            message = message.split('• Quota exceeded')[0].trim();
        }

        if (message.toLowerCase().indexOf('quota') !== -1 || message.toLowerCase().indexOf('429') !== -1) {
            return warningSvgIcon + '<strong>Quota Exceeded (429):</strong> You have exceeded your API usage limit. Please check your account plan and billing details.';
        }

        return warningSvgIcon + ' ' + escapeHtml(message);
    }

    // 5. Speech Synthesis (Text-to-Speech)
    function speakText(text, ttsUrl = '', targetBtn = null) {
        stopSpeaking();

        if (targetBtn && targetBtn.length) {
            activeReplayBtn = targetBtn;
            targetBtn.addClass('playing');
            targetBtn.find('.dashicons').attr('class', 'dashicons dashicons-controls-pause');
            targetBtn.find('.aiva-btn-text').text('Playing...');
            targetBtn.closest('.aiva-audio-controls-row').addClass('is-playing');
        }

        // Play premium ElevenLabs audio if available.
        if (ttsUrl) {
            activeAudio = new Audio(ttsUrl);

            activeAudio.onplay = function () {
                setWidgetState('speaking');
            };

            activeAudio.onended = function () {
                setWidgetState('idle');
                stopSpeaking();
            };

            activeAudio.onerror = function (e) {
                console.error('[AIVA] ElevenLabs audio playback error, falling back to browser speech:', e);
                speakBrowserSpeech(text, targetBtn);
            };

            activeAudio.play().catch(function (err) {
                console.error('[AIVA] Audio play failed, falling back:', err);
                speakBrowserSpeech(text, targetBtn);
            });

            return;
        }

        speakBrowserSpeech(text, targetBtn);
    }

    function speakBrowserSpeech(text, targetBtn = null) {
        if ('speechSynthesis' in window) {
            // Strip HTML elements out before reading
            var plainText = text.replace(/<[^>]*>/g, '');
            // Strip markdown asterisks so the TTS engine doesn't read them aloud
            plainText = plainText.replace(/\*/g, '');

            var selectedLang = $('#aiva-lang-select').val() || 'en-US';
            activeUtterance = new SpeechSynthesisUtterance(plainText);
            activeUtterance.lang = selectedLang;

            // Attempt to assign a default high-quality voice
            var voices = window.speechSynthesis.getVoices();
            if (voices.length > 0) {
                var langPrefix = selectedLang.split('-')[0].toLowerCase();
                var hasLangVoice = voices.some(function (v) {
                    return String(v.lang || '').replace('_', '-').toLowerCase().split('-')[0] === langPrefix;
                });
                if (!hasLangVoice) {
                    // No voice for this language on this device: don't read it with a wrong-language voice.
                    if (!window.aivaNoVoiceNoticeShown) {
                        window.aivaNoVoiceNoticeShown = true;
                        appendMessage(escapeHtml(aivaI18n.noVoice || 'Spoken replies are not available for this language on this device, so the answer is shown as text.'), 'bot', [], [], '', true);
                    }
                    setWidgetState('idle');
                    resetReplayButtons();
                    return;
                }
                var preferredVoice = voices.find(function (v) {
                    return v.lang.startsWith(langPrefix) && (v.name.includes('Google') || v.name.includes('Natural') || v.name.includes('Microsoft') || v.name.includes('Premium'));
                });
                if (preferredVoice) {
                    activeUtterance.voice = preferredVoice;
                }
            }

            activeUtterance.onstart = function () {
                setWidgetState('speaking');
                if (targetBtn && targetBtn.length) {
                    activeReplayBtn = targetBtn;
                    targetBtn.addClass('playing');
                    targetBtn.find('.dashicons').attr('class', 'dashicons dashicons-controls-pause');
                    targetBtn.find('.aiva-btn-text').text('Playing...');
                    targetBtn.closest('.aiva-audio-controls-row').addClass('is-playing');
                }
            };

            activeUtterance.onend = function () {
                setWidgetState('idle');
                stopSpeaking();
            };

            activeUtterance.onerror = function (event) {
                console.error('Speech Synthesis Error:', event.error);
                setWidgetState('idle');
                stopSpeaking();
            };

            window.speechSynthesis.speak(activeUtterance);
        } else {
            setWidgetState('idle');
            resetReplayButtons();
        }
    }

    function stopSpeaking() {
        if (activeAudio) {
            activeAudio.pause();
            activeAudio = null;
        }
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        resetReplayButtons();
        $('#aiva-stop-speech').hide();
        isSpeaking = false;
    }

    // 6. Event Listeners for Operations
    $('#aiva-mic-trigger').on('click', function () {
        if (isSpeaking) {
            stopSpeaking();
            setWidgetState('idle');
            return;
        }

        if (isListening) {
            if (recognition) recognition.stop();
            setWidgetState('idle');
            return;
        }

        stopSpeaking();
        if (recognition) {
            try {
                recognition.start();
            } catch (e) {
                // Catch instances where recognition was still cleaning up
                recognition.stop();
                setTimeout(function () {
                    recognition.start();
                }, 400);
            }
        }
    });

    // Quick reply buttons: starter chips (rendered by PHP) and follow-up chips (sent by the server).
    function renderQuickReplies(items) {
        $('#aiva-chat-messages .aiva-quick-followup').remove();
        if (!items || !items.length) return;
        var $wrap = $('<div class="aiva-quick-replies aiva-quick-followup" role="group"></div>');
        items.forEach(function (item) {
            if (!item || !item.label) return;
            $('<button type="button" class="aiva-quick-chip"></button>')
                .text(item.label)
                .attr('data-message', item.message || item.label)
                .appendTo($wrap);
        });
        if ($wrap.children().length) {
            $('#aiva-chat-messages').append($wrap);
            scrollToBottom();
        }
    }

    $(document).on('click', '#aiva-chat-messages .aiva-quick-chip', function () {
        if (isThinking) return;
        var message = String($(this).attr('data-message') || $(this).text()).trim();
        if (!message) return;
        stopSpeaking();
        handleQueryInput(message);
    });

    // Helper to process queries and intercept positive confirmations
    function handleQueryInput(queryText) {
        // Once the visitor asks something, starter and old follow-up chips are no longer needed.
        $('#aiva-chat-messages .aiva-quick-replies').remove();
        var positiveConfirmations = ['yes', 'sure', 'yeah', 'yup', 'ok', 'okay', 'show me', 'open it', 'show them', 'show', 'open'];
        var queryLower = queryText.toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()?]/g, "").trim();

        if (pendingProducts.length > 0 && positiveConfirmations.indexOf(queryLower) !== -1) {
            appendMessage(queryText, 'user');
            appendMessage("Opening the product page for you now...", 'bot');
            speakText("Opening the product page for you now.");

            var productUrl = pendingProducts[0].url;
            safeOpen(productUrl, pendingProducts[0].name);

            pendingProducts = []; // Clear pending queue
            return;
        }

        appendMessage(queryText, 'user');
        sendQueryToServer(queryText);
    }

    // Keyboard Fallback Submit
    function submitTextInput() {
        var queryInput = $('#aiva-text-query');
        var queryText = queryInput.val().trim();
        if (queryText === '') return;

        queryInput.val('');
        stopSpeaking();
        handleQueryInput(queryText);
    }

    $('#aiva-send-text').on('click', function () {
        submitTextInput();
    });

    $('#aiva-text-query').on('keypress', function (e) {
        if (e.which === 13) { // Enter key
            submitTextInput();
        }
    });

    // Stop speaking action
    $('#aiva-stop-speech').on('click', function () {
        stopSpeaking();
        setWidgetState('idle');
    });

    // Language selector change handler
    $('#aiva-lang-select').on('change', function () {
        var selectedLang = $(this).val();
        if (recognition) {
            recognition.lang = selectedLang;
        }
        stopSpeaking();
    });

    // --- Language start-up: remembered choice > browser language (if enabled) > site default.
    var aivaLangCfg = (window.aivaPublic && aivaPublic.languages) ? aivaPublic.languages : { enabled: [], rtl: [] };

    function isRtlLang(code) {
        return (aivaLangCfg.rtl || []).indexOf(code) !== -1;
    }

    function applyLanguage(code, remember) {
        var $opt = $('.aiva-lang-option[data-value="' + code + '"]');
        if (!$opt.length) return;
        $('#aiva-lang-select').val(code).trigger('change');
        $('.aiva-lang-option').removeClass('active').find('.dashicons').remove();
        $opt.addClass('active').append(' <span class="dashicons dashicons-yes"></span>');
        $('.aiva-lang-current-label').text($opt.contents().filter(function () { return this.nodeType === 3; }).text().trim());
        $('#aiva-text-query').attr('dir', isRtlLang(code) ? 'rtl' : 'auto');
        window.aivaNoVoiceNoticeShown = false;
        if (remember) {
            try { window.localStorage.setItem('aiva_lang', code); } catch (err) { /* storage unavailable */ }
        }
    }

    (function initLanguage() {
        var enabled = aivaLangCfg.enabled || [];
        var chosen = '';
        try { chosen = window.localStorage.getItem('aiva_lang') || ''; } catch (err) { chosen = ''; }
        if (chosen && enabled.indexOf(chosen) === -1) chosen = '';

        if (!chosen && aivaLangCfg.autoDetect) {
            var prefs = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || ''];
            for (var i = 0; i < prefs.length && !chosen; i++) {
                var pref = String(prefs[i] || '').toLowerCase();
                if (!pref) continue;
                // Exact match first (e.g. "pt-br"), then same base language (e.g. "ur" -> "ur-PK").
                enabled.forEach(function (code) {
                    if (!chosen && code.toLowerCase() === pref) chosen = code;
                });
                enabled.forEach(function (code) {
                    if (!chosen && code.toLowerCase().split('-')[0] === pref.split('-')[0]) chosen = code;
                });
            }
        }

        if (chosen && chosen !== $('#aiva-lang-select').val()) {
            applyLanguage(chosen, false);
        } else {
            $('#aiva-text-query').attr('dir', isRtlLang($('#aiva-lang-select').val()) ? 'rtl' : 'auto');
        }
    })();

    // Custom Language Dropdown Toggle & Selection
    $(document).on('click', '#aiva-lang-custom-toggle', function (e) {
        e.stopPropagation();
        var dropdown = $('.aiva-lang-custom-dropdown');
        dropdown.toggleClass('open');
        $('#aiva-lang-custom-menu').fadeToggle(150);
    });

    $(document).on('click', function () {
        $('.aiva-lang-custom-dropdown').removeClass('open');
        $('#aiva-lang-custom-menu').fadeOut(150);
    });

    $(document).on('click', '.aiva-lang-option', function (e) {
        e.stopPropagation();
        var val = $(this).data('value');
        applyLanguage(val, true);
        $('#aiva-lang-custom-menu').fadeOut(150);
        $('.aiva-lang-custom-dropdown').removeClass('open');
    });

    // Replay response handler
    $(document).on('click', '.aiva-replay-btn', function () {
        var btn = $(this);
        if (btn.hasClass('playing')) {
            stopSpeaking();
            setWidgetState('idle');
            return;
        }

        var parentMsg = btn.closest('.aiva-msg');
        var bubble = parentMsg.find('.aiva-bubble');
        var plainText = bubble.text();
        var ttsUrl = btn.attr('data-tts-url') || btn.data('tts-url') || '';

        speakText(plainText, ttsUrl, btn);
    });

    // Load voices on change (for Chrome support compatibility)
    if ('speechSynthesis' in window && window.speechSynthesis.onvoiceschanged !== undefined) {
        window.speechSynthesis.onvoiceschanged = function () {
            window.speechSynthesis.getVoices();
        };
    }
});