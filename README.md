=== Shopwalker – AI Voice & Chat Assistant ===
Contributors: cubixsol
Tags: ai, voice assistant, chatbot, woocommerce, gemini
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI voice sales & support assistant for WordPress & WooCommerce. Visitors speak or chat; answers come from your own site content.

== Description ==

**Shopwalker – AI Voice & Chat Assistant** adds an interactive voice & text AI sales representative and 24/7 customer support assistant to WordPress and WooCommerce.

Using **Retrieval-Augmented Generation (RAG)** with vector embeddings, the agent indexes your WooCommerce products, posts, pages, custom post types, navigation menus and business profile, then answers visitor questions grounded in that content instead of general internet knowledge.

Visitors tap the mic button to speak in their language or type into the chat box. The agent replies with free browser speech synthesis or, optionally, ElevenLabs studio voices.

= Why Choose Shopwalker? =

* **Boost Store Conversions**: Instant product recommendations, prices, stock details and store policies through natural conversation, with product cards and page links shown in the chat.
* **Grounded Answers (RAG)**: Answers are built from your indexed website content, and the agent is instructed not to invent prices, discounts or policies.
* **Multi-Engine AI**: Choose Google Gemini (Gemini 3.5 Flash recommended), OpenAI (GPT-4o mini, GPT-5.x) or Anthropic Claude (Claude Haiku 4.5, Sonnet), or enter any custom model ID.
* **Natural Speech Output**: Free, instant browser speech synthesis, or ElevenLabs for studio-grade human-like voices.
* **50+ Languages**: Choose which languages visitors can pick (English, Spanish, French, German, Italian, Portuguese, Arabic, Urdu, Hindi, Bengali, Turkish, Chinese, Japanese, Korean and many more), set a default language, match the visitor's browser language automatically, or hide the selector completely. Right-to-left languages are displayed correctly.
* **Modern Admin Dashboard**: Knowledge base scanner with live log, database inspector, path exclusions and persona templates.

= Key Features =

* **Multi-Engine AI Integration**: Switch between Google Gemini, OpenAI and Anthropic Claude.
* **Live WooCommerce Catalog Search**: Visitors can ask "show me all products", "anything between 100 and 300?", "do you have cufflinks?" or "what categories do you have?". The agent reads the answer straight from your WooCommerce catalog (categories, price ranges, product names, SKUs, stock), so new products work the moment you publish them and prices are always current.
* **Smart Product Answers**: Visitors can ask "which product is most popular?", "what are your top rated items?", "anything on sale?", "what's new?", "what do customers say about X?". Answers use real sales (best-seller ranking, without revealing sales numbers), customer ratings and recent reviews, sale prices and discounts, stock (following your WooCommerce stock display setting), attributes, tags, featured products, weight and dimensions.
* **RAG Vector Knowledge Base**: Built-in scanning & indexing for WooCommerce products, pages, posts, custom post types, menus and your business profile.
* **Automatic Sync**: Content is re-indexed in the background when you publish or update it, removed when it is unpublished or deleted, and a daily background sync catches anything missed.
* **Quick Reply Buttons**: Tappable suggestion buttons under the greeting ("What do you sell?", "Shipping & delivery", "Browse Cuff Links"…) so visitors know what to ask. After shopping answers, smart follow-ups appear (cheapest option, other categories, show all products). Fully editable in Settings > Widget.
* **Visitor Language Options**: Settings > Widget > Visitor Languages lets you enable any of 54 languages, pick the default, and decide whether visitors see the language selector.
* **Voice & Text Input**: Real-time speech recognition (Web Speech API) plus keyboard chat.
* **Text-to-Speech**: Browser speech synthesis or ElevenLabs, with an audio cache to save API credits.
* **Granular Exclusions & Rules**: Exclude URL paths (e.g. `/checkout`, `/my-account`), choose which content types are indexed, and toggle menus, FAQs, contact details and policies.
* **Database Inspector**: View, search and delete indexed content chunks from the admin dashboard.
* **Persona Templates & Custom Instructions**: Start from built-in persona templates or write your own guidelines.
* **Widget Controls**: Launcher style, position, texts, and which pages show the widget.
* **Built-in Protection**: The public chat endpoint is nonce-protected and rate-limited per visitor, to protect your API credits.

= Privacy =

Password-protected, private and draft content is never indexed. Protected (underscore) post meta and meta fields that look like personal or secret data are skipped. Conversations are not stored on your website. The plugin adds suggested text to **Settings > Privacy** describing what is sent to the AI services. Fonts are bundled locally; no requests are made to third-party font servers.

== Trademarks ==

Shopwalker is an independent plugin. It is not affiliated with, endorsed by or sponsored by WordPress, WooCommerce, Google, OpenAI, Anthropic or ElevenLabs. All product names, logos and trademarks are the property of their respective owners and are used only to describe compatibility.

== External Services ==

This plugin connects to third-party APIs to generate answers, create vector embeddings and synthesize speech. Nothing is sent until you add the relevant API key.

**Google Gemini API** (Google LLC) — required for indexing.
Used to create vector embeddings of your website content (always), to embed visitor questions for search, and to generate answers when Gemini is the selected engine.
Data sent: chunks of your published website content when indexing; the visitor's question, recent conversation turns and matching website content when answering.
Terms of Service: https://ai.google.dev/terms
Privacy Policy: https://policies.google.com/privacy

**OpenAI API** (OpenAI) — optional.
Used to generate answers when OpenAI is the selected engine.
Data sent: the visitor's question, recent conversation turns and matching website content.
Terms of Use: https://openai.com/policies/terms-of-use/
Privacy Policy: https://openai.com/policies/privacy-policy/

**Anthropic API** (Anthropic PBC) — optional.
Used to generate answers when Claude is the selected engine.
Data sent: the visitor's question, recent conversation turns and matching website content.
Commercial Terms: https://www.anthropic.com/legal/commercial-terms
Privacy Policy: https://www.anthropic.com/legal/privacy

**ElevenLabs API** (ElevenLabs Inc.) — optional.
Used to turn the assistant's answer into speech when ElevenLabs voice is enabled.
Data sent: the text of the assistant's answer.
Terms of Service: https://elevenlabs.io/terms
Privacy Policy: https://elevenlabs.io/privacy

**Browser speech services** — Speech recognition and browser speech synthesis use the Web Speech API built into the visitor's browser. Depending on the browser, the browser vendor may process the audio under its own privacy policy.

== Installation ==

1. Upload the `shopwalker-ai-voice-chat-assistant` folder to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Shopwalker > Settings**, add your Google Gemini API key (and optionally OpenAI, Anthropic or ElevenLabs keys) and fill in your business profile.
4. Go to **Shopwalker > Dashboard** and click **Scan & Index Website**.

== Frequently Asked Questions ==

= Which AI models are supported? =

Google Gemini (Gemini 3.5 Flash recommended, plus 3.8 Flash and Flash-Lite models), OpenAI (GPT-4o mini, GPT-5.x and more) and Anthropic Claude (Claude Haiku 4.5, Claude Sonnet). You can also enter any custom model ID offered by the provider.

= How does the voice input work? =

When a visitor taps the microphone, their browser's built-in speech recognition (Web Speech API) turns their speech into text. That text goes through exactly the same pipeline as a typed message. The text box is there for browsers without speech recognition (for example Firefox), for noisy places, and for visitors who prefer typing.

= How does the agent find my products? =

Two ways, used together. Shopping questions (categories, price ranges, "show me all products", product names or SKUs) are answered live from WooCommerce. General questions (policies, shipping, page content, FAQs) are answered from the vector knowledge base built by **Scan & Index Website**. Products are only included when "Products" is enabled under Content Selection.

= Which languages work with voice? =

The assistant can answer in all 54 languages. Voice input and the free browser voice depend on the visitor's browser and device: Chrome and Edge support the most languages, and a device may not have a voice installed for every language. When no voice is available the answer is shown as text, and visitors can always type instead of speaking. ElevenLabs multilingual voices can speak most of the languages. Developers can add or change languages with the `aiva_languages` filter.

= I updated the plugin. Do I need to re-scan? =

Yes, run **Scan & Index Website** once after updating. Indexed content is now cleaner (no empty lines left by the block editor) and every part of a long page or product remembers which page or product it belongs to, so answers about your posts and pages are more complete. Live product questions (prices, stock, popularity, ratings) do not need a re-scan.

= Can I change the quick reply buttons? =

Yes. Go to **Settings > Widget > Quick Reply Buttons**. Write one button per line as `Button label | Question sent to the assistant`. You can also turn on automatic "Browse category" buttons for your top WooCommerce categories, or switch the buttons off completely. Developers can use the `aiva_quick_replies` and `aiva_follow_up_suggestions` filters.

= Do I need a Gemini key if I use OpenAI or Claude? =

Yes. The knowledge base search index is always built with Google's Gemini embedding model, so a Gemini API key is required. OpenAI or Claude can then be used to write the answers.

= Do I need a separate API key for embeddings? =

No. One Google Gemini API key covers both answers and embeddings.

= I updated from an earlier build and the agent cannot find my content =

This version stores smaller, faster 768-dimension embeddings. Run **Scan & Index Website** once from the dashboard to rebuild the index. Until then, the agent falls back to keyword search.

= Can visitors abuse the chat and use up my API credits? =

The public endpoint is protected with a nonce and a per-visitor rate limit (30 messages per 10 minutes by default). Developers can change this with the `aiva_rate_limit_requests` and `aiva_rate_limit_window` filters. If your site is behind a proxy or CDN, use the `aiva_client_ip` filter to supply the real visitor IP.

= Does it work with page caching? =

Yes. If a cached page contains an expired security token, the widget fetches a fresh one automatically.

== Changelog ==

= 1.0.0 =
* Initial release with multi-engine support (Gemini, OpenAI, Claude), vector knowledge base indexing, multi-language speech pipeline and responsive widget interface.
