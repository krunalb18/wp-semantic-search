=== Embedix AI Search for Posts ===
Contributors: krunalb18
Tags: search, semantic search, AI search, OpenAI, embeddings
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered semantic search for WordPress posts. Understands meaning, not just keywords. Powered by OpenAI or Google Gemini.

== Description ==

Embedix AI Search for Posts replaces WordPress's default SQL LIKE search with vector-based semantic search. Instead of matching exact words, it understands the *meaning* of a search query.

**Example:** A user searching "heart workouts" will find your post titled "Best cardiovascular exercises" - even though no words overlap - because the meanings are similar.

= How It Works =

1. When you publish a post, the plugin sends its content to your chosen AI provider (OpenAI or Google Gemini) to generate a vector embedding - a list of numbers that represents the meaning of the text.
2. These numbers are stored in your database.
3. When a user searches, their query is also converted to numbers, and the plugin finds posts whose numbers are closest - meaning most semantically similar.

= Features =

* Semantic search powered by OpenAI (`text-embedding-3-small` or `text-embedding-3-large`) or Google Gemini (`gemini-embedding-001`)
* Hybrid re-ranking: combines semantic similarity with keyword matching for best results
* Gutenberg block and shortcode `[semantic_search]` for easy placement
* Bulk indexer with progress bar for existing posts
* WP-CLI support: `wp semantic-search index`, `wp semantic-search status`, `wp semantic-search search "query"`
* Configurable scoring thresholds via Settings -> Semantic Search
* Automatic re-indexing when you switch embedding models
* Results cached via WordPress object cache (Redis/Memcached compatible)

= Third-Party Services =

This plugin sends post content to external AI APIs to generate embeddings. By using this plugin, you agree to the terms of service and privacy policies of your chosen provider:

* **OpenAI:** https://openai.com/policies/privacy-policy | https://openai.com/policies/terms-of-use
* **Google Gemini:** https://policies.google.com/privacy | https://ai.google.dev/terms

No data is sent without your API key being configured. Data is only transmitted when posts are published or during bulk indexing.

= Performance Note =

Semantic search requires loading all embeddings into PHP memory for comparison. This works well for sites with up to approximately 1,500 posts. For larger sites, a dedicated vector database (pgvector, Qdrant, or Pinecone) is recommended.

== Installation ==

1. Upload the `ai-semantic-search-for-posts` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings -> Semantic Search**
4. Enter your OpenAI or Google Gemini API key
5. Click **Test Connection** to verify the key works
6. Click **Start Bulk Index** to index your existing posts
7. Add the search block to any page via the Gutenberg editor, or use the shortcode `[semantic_search]`

== Frequently Asked Questions ==

= Do I need an OpenAI account? =

Yes. You need either an OpenAI API key (https://platform.openai.com) or a Google Gemini API key (https://aistudio.google.com). Both have free tiers that are sufficient for small sites.

= Does this replace the default WordPress search? =

No. This plugin adds a separate search widget/block. Your default WordPress search continues to work unchanged. You can place the semantic search widget on any page alongside or instead of the default search.

= Is my content sent to OpenAI/Google? =

Yes - post content is sent to the embedding API when you publish a post or run bulk indexing. The API returns numbers (the embedding vector) and does not store or use your content for training. See their privacy policies for full details.

= How much does it cost? =

Using `text-embedding-3-small` (the default), indexing costs approximately $0.002 per 1,000 posts. Each search query costs a fraction of a cent. For most sites, monthly costs are under $1.

= What happens if I change the embedding model? =

The plugin detects the model change and automatically re-indexes all posts using the new model. During re-indexing, searches will return no results until indexing is complete.

= Can I use this on a multisite installation? =

The plugin has not been tested on WordPress Multisite and is not officially supported in that environment.

== Changelog ==

= 1.0.0 =
* Initial public release
* OpenAI and Google Gemini embedding support
* Hybrid semantic + keyword re-ranking (BM25)
* Gutenberg block and shortcode
* WP-CLI commands
* Bulk indexer with progress UI
* Configurable scoring thresholds
* Redis/Memcached compatible caching
* API connection test in admin
* Automatic re-index on model change

== Upgrade Notice ==

= 1.0.0 =
Initial release. Run bulk indexing after activation to index existing posts.
