# AI Support Agent (WordPress plugin)

AI customer-support chatbot for WordPress: website knowledge base (RAG), document knowledge,
WooCommerce tools, tool calling, lead collection, conversation history, human handoff, admin analytics.

## Install
1. Upload the `ai-support-agent` folder (or the zip) via Plugins → Add New → Upload, then activate.
2. AI Support → Settings: pick a provider, add an API key (or `define( 'AISA_API_KEY', '...' );` in wp-config.php), set the model.
3. AI Support → Knowledge → "Reindex site content". Add documents there too.
4. Visit the site and chat.

## PDF uploads (optional)
`composer require smalot/pdfparser` inside the plugin folder. .txt, .md and .csv work without it.

## Extend
- Add tools: `add_filter( 'aisa_tools', ... )` (see includes/class-aisa-tools.php).
- Edit the prompt: `add_filter( 'aisa_system_prompt', ... )`.
- React to leads: `add_action( 'aisa_lead_collected', function ( $lead_id, $email, $data ) {} , 10, 3 );`

## Known limits (MVP)
- Responses are not streamed; handoff replies use 5 s polling.
- Retrieval is MySQL FULLTEXT (needs MySQL 5.6+/MariaDB 10.0.5+), not vector search.
- Not yet: WordPress privacy exporter/eraser hooks, multisite testing, automated tests.
