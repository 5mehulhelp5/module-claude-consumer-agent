# MageOS_ClaudeConsumerAgent

An AI shopping assistant for Hyva storefronts. It ports Anthropic's open-source
shopping agent to a Magento 2 / Mage-OS module: search, product detail, cart,
orders and store-policy tools run against the store's own catalog, quote and
sales data, and stream through the Claude Messages API to a chat overlay in
the storefront. The module ships generic: it names no real store, and every
backend call goes through `Api\StorefrontBackendInterface`, so a store layer
can replace or extend behaviour without touching the base module.

## Requirements

- PHP 8.1 through 8.5
- Magento 2.4 / Mage-OS 3.x with the Hyva theme module, on a Hyva-only
  storefront (the module has no Luma templates)
- ext-intl, ext-json
- guzzlehttp/guzzle ^7.5, for the Messages API client
- An Anthropic API key with access to the configured model

## Install

1. Copy or clone this module into `app/code/MageOS/ClaudeConsumerAgent`
2. `bin/magento module:enable MageOS_ClaudeConsumerAgent`
3. `bin/magento setup:upgrade` - creates the three tables, `aiagent_session`,
   `aiagent_message` and `aiagent_turn`
4. `bin/magento hyva:config:generate` - adds the module's path to
   `app/etc/hyva-themes.json`, so the theme build scans the module's
   templates and imports its Tailwind source on its own
5. `cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm ci && npm run build`
   - on Hyva 1.5 this runs `hyva-sources` first, then Tailwind 4. A deploy
   pipeline that already runs this build needs nothing else
6. `bin/magento setup:static-content:deploy` in production mode, then
   `bin/magento cache:flush`
7. In admin: Stores > Configuration > Sales > Shopping Assistant. Set the API
   key, model, voice and policy pages, then set Enabled to Yes at the store
   view scope
8. Only if the theme CSS is not rebuilt after step 4 (for example a store
   that commits `styles.css` and has no build step on deploy): turn on the
   bundled fallback stylesheet with
   `bin/magento config:set aiagent/general/use_bundled_css 1`. The flag is
   not in the admin and defaults to 0; switch it back off once the theme CSS
   includes the module

## Configuration

Every field lives under Stores > Configuration > Sales > Shopping Assistant
(config path `aiagent`), with `showInDefault`, `showInWebsite` and
`showInStore` all set, so one install can run several store views with their
own name, voice, starters, policy pages and limits.

- **general** - enabled, surface mode (cart / overlay), launcher toggle,
  product-page block toggle, header icon view (cart / chat / last used, cart
  mode only), streaming replies. The overlay is the default surface; cart mode docks the
  assistant into the theme's cart drawer. `general/use_bundled_css` is a
  hidden flag, see Install step 8
- **model** - API key (encrypted), model id, max tokens, thinking effort
  (off / low / medium / high), request and connect timeouts
- **voice** - brand name, assistant name, brand voice, greeting, starter
  prompts (one per line), domain search notes fed into the prompt
- **content** - policy pages, allowed categories, catalog map depth (Off / 1 / 2 / 3),
  catalog map roots, catalog map max characters
- **cards** - show image, show price, show short description, show stock
  status, show Add to cart button, show the assistant's reason, each a
  Yes/No toggle for what a product card renders
- **limits** - concurrent turns, turns per session, turns-per-session window,
  turns per IP per minute, max tool iterations, max quantity per item, max
  cart lines, max message length, turn wall clock, max search results, max
  fenced characters, compact-above-tokens
- **runtime** - only `streaming` (auto / off) is in the admin, shown in the
  General group. `first_byte_threshold` and `heartbeat_seconds` are hidden
  config paths with defaults in `config.xml`
- **lexicon** - hidden config paths (`product_id_patterns`,
  `policy_intent_terms`, `order_intent_terms`) with defaults in `config.xml`,
  each merged with any terms a store layer adds through `Lexicon`'s di.xml
  arguments
- **privacy** - retention days, show AI label, contact URL and label, debug
  log

`Model\Config\StoreConfig::agent(int $storeId)` builds one `AgentConfig`
snapshot per call and caches it for the request. The static system prompt and
tool list are cached per store view under a key that includes a hash of the
prompt-bearing fields (brand name, assistant name, brand voice, domain search
notes, the enable-* flags, the model id and the catalog map depth/roots/max
characters), so the cache resets by itself when a voice, model or catalog map
setting changes.

### Catalog map

`aiagent/content/catalog_map_depth` (Off / 1 / 2 / 3) controls how many
levels of the category tree are printed into the static system prompt under
a `# Catalog map` heading, built from `Api\Backend\CatalogMapProviderInterface`
(default `Model\Backend\Provider\CategoryTree`) and formatted by the pure
`Model\Agent\Prompt\CatalogMap::text()`. `aiagent/content/catalog_map_roots`
picks the categories the map is built from; left empty, the map uses every
active, menu-visible category one level below the store's root category.
`aiagent/content/catalog_map_max_chars` (default 6000) caps the map text;
past the cap whole lines are dropped and the map ends with a line pointing
the model at `search_categories`. Depth 1 prints roots only, depth 2 adds
their children, depth 3 also prints one line per child listing its own
children. The map is rebuilt whenever a category is saved, deleted or moved
(`Observer\CategoryChanged`, on `catalog_category_save_after`,
`catalog_category_delete_after` and `catalog_category_move_after`), the same
way `Observer\ConfigChanged` invalidates the prompt cache on a config save.

Each map entry carries its `category_id` in brackets so the model can pass it
straight to `search_products` as `filters.category_id`, or word a search,
chip or clarifying question in the catalog's own vocabulary. A kind of
product the map does not show may still exist deeper in the tree; the
`search_categories` tool finds it by keyword at any level and returns
`category_id`, the full path and the product count, so the model can check
before telling the customer the store does not carry something.

`search_products`'s `query` argument is now optional: the model may call it
with only `filters.category_id` (from the map or from `search_categories`)
to list a category's products, sorted by position unless `filters.sort` is
`price_asc`, `price_desc` or `best_sellers`. The handler rejects a call
carrying neither a query nor a `category_id` with an error outcome; a query
with no category still runs the ordinary text search. `filters.category_id`
takes precedence over `filters.category` (a name lookup) and over
`allowed_categories` in `Model\Backend\Provider\FulltextSearch`.

`filters.sort = best_sellers` ranks results by units sold in the store over
the last two years rather than by relevance or position. `FulltextSearch`
runs the underlying search or category listing with its own order (relevance
for a query, position for a listing) at a widened page size,
`min($limit * 5, 60)`, then hands the resulting ids to
`Api\Backend\BestsellerRankInterface::rank()` and slices the reordered list
back down to `$limit`. The default `Model\Backend\Provider\AggregatedBestsellerRank`
sums `qty_ordered` from `sales_bestsellers_aggregated_yearly` for the store,
restricted to the candidate ids and the configurable children found through
`catalog_product_super_link` (a child's quantity folds into its configurable
parent), for `period` back to the first day of last year; an id with no
matching sales sorts last, in its original order.

## Page context

The assistant's Session context block carries a `current_page` object built
from `Api\Data\PageContextInterface`: `page_type` (`home`, `search`,
`product`, `category`, `cart`, `orders` or `other`), `product_id`, `query`,
and, for a category page, `category_id` (what tools take) and
`category_name` (display only). `ViewModel\Assistant::snapshot()` builds a
`page` key server-side from `Model\Surface\PageDetector`, which maps
`RequestInterface::getFullActionName()` (`catalog_category_view` to
`category` with `category_id` from the `id` param and `category_name` from
`CategoryRepositoryInterface::get()`, `catalog_product_view` to `product`,
`catalogsearch_result_index` to `search` with the `q` param, `cms_index_index`
to `home`, `checkout_cart_index` to `cart`, any `sales_order_*` action to
`orders`, anything else to `other`). `js/store.phtml`'s `detectPage()` uses
that server value whenever it is not `other`, and falls back to sniffing
`document.body`'s classes and DOM only when it is (a page served without the
server value, or the `#ai-agent-ask` product-page override). The prompt in
`Model\Agent\Prompt\StaticSystem` tells the model that "this category" and
similar phrases on a category page mean `current_page.category_id`, to be
passed as `filters.category_id`.

## Streaming and the JSON fallback

`POST /aiagent/turn/index` streams Server-Sent Events by default: a `: open`
comment within milliseconds, then one frame per event
(`event: <type>\ndata: <json>\n\n`), periodic `: ping` heartbeats, then
`event: turn_complete`. A client can also request `{"stream": 0}`, in which
case the same events come back as one JSON body,
`{"events": [{"type": ..., "data": ...}, ...]}`, after the turn finishes.

A buffering proxy defeats SSE: the browser sees nothing until the whole
response lands, several seconds later. Checks and fixes:

- nginx: `proxy_buffering off;` and `gzip off;` (or exclude the route) on the
  `aiagent` location, and do not `fastcgi_finish_request` early
- Apache with mod_proxy_fcgi: disable `SetOutputFilter` gzip on this route,
  and confirm `flush` is on for the proxy handler
- Either way, do not run the response through a compression filter - a
  gzip buffer holds the whole stream before it flushes

If the proxy cannot be fixed, set `runtime/streaming` to Off. Every client in
this module already understands JSON mode, so the assistant keeps working
with one round-trip per turn instead of a live stream.

Health check, from the server, through the proxy:

```
curl -N -s -X POST https://<store>/aiagent/turn/index \
  -H 'Content-Type: application/json' -H 'X-Form-Key: <form key>' \
  -H 'Cookie: PHPSESSID=<session>' \
  --data '{"session":null,"message":"hello","page":{"type":"home"},"stream":1}'
```

Expect `: open` within 200 ms, then event frames, then `event: turn_complete`.
A body that arrives all at once after several seconds means a buffering
proxy, not a Magento cache problem.

## Bundled CSS scoping

Rebuild the fallback stylesheet with `sh view/frontend/tailwind/bundled/build.sh <path to a Hyva theme web/tailwind directory that has node_modules>`; the script runs the Tailwind CLI against the module templates with the Hyva default tokens and scopes the output with `scope.php`.

`view/frontend/web/css/aiagent.css` is a standalone Tailwind v4 build (loaded
at the end of body) for stores that have not yet added this module's Tailwind
source to their own theme build. Every rule in it, base layer, component
layer, utility layer and the `--tw-*` property defaults, is wrapped in
`@scope (#ai-agent-overlay, #ai-agent-cards, #ai-agent-drawer-panel,
#ai-agent-launcher, #ai-agent-cart-ask)` so the bundle can only ever style
descendants of the module's own surfaces, never the rest of the page; because
a bare selector inside `@scope` does not match the scope root itself, each
selector that has no combinator also gets an explicit `:scope`-prefixed
alternative (for example `.btn, :scope.btn`) so the root elements' own
utility classes still apply. The design-token layer's `:root,:host` selector
is rewritten to `:where(...)` over the same five ids instead, since `:root`
can never match inside a scope. `@scope` is supported by evergreen browsers
since 2024. The file is only loaded when `general/use_bundled_css` is 1 (see
Install, step 8); a theme rebuilt with the module registered (Install, step
4) does not need it.

## Lazy loading

Every page carries three things for this module: the config store
(`js/store.phtml`, inline), the launcher button (`surface/launcher.phtml`,
inline) and, where enabled, the product-page and cart-page ask buttons
(`product/ask.phtml`, `cart/ask.phtml`, both inline). The bundled CSS
`<link>`, when `general/use_bundled_css` is on, stays eager too. Everything
else, the overlay dialog, the side-cart drawer panel, the transcript,
composer, start screen and every card, sits inside a `<template
data-ai-agent-shell>` element (`ai-agent-shell` for the overlay in
`surface/overlay.phtml`, `ai-agent-drawer-shell` for the side-cart panel in
`surface/drawer.phtml`). Alpine never walks into a `<template>`, so none of
those Alpine components initialise on page load.

Suggestion chips render above the textarea in `surface/composer.phtml`, not
as a card in the transcript; the store keeps one current set on
`suggestions`, and `restore()` lifts the last set out of the reloaded
transcript rather than leaving it as a card on an old message.

The component constructors and the SSE reader that used to be inline scripts
on every one of those templates now live in one static file,
`view/frontend/web/js/aiagent.js`, loaded with a dynamic `<script src>` only
when the assistant is first opened. `Alpine.store('aiAgent').mount()` is the
single entry point: it loads the script once, clones every shell template's
content into the document and calls `Alpine.initTree()` on the inserted
nodes, then flips `store.ready` to true. Bootstrap listeners on
`ai-agent:open` and `toggle-cart` call `mount()` before the surface that
actually handles the event exists, so a click on the launcher or an ask
button works the same as before, just with one extra script fetch on the
first open.

Every string a moved component needs, `__()` translations and URLs alike,
comes from the config snapshot's `i18n` map and `urls` (built in
`ViewModel\Assistant::snapshot()`) or from a server-rendered attribute on
the surrounding markup; `aiagent.js` itself has no PHP and pulls nothing
through Magento's translation layer directly.

A store layer that adds a card through
`Api\Presentation\PresentationExtensionInterface` must register its own
Alpine component, the same way `aiagent.js` registers the built-in ones,
because a card template is not itself a lazy-load shell: its markup lives
inside the eager `#ai-agent-cards` container, so a `<script>` tag on that
template still executes on every page load like it did before this change.
Only markup injected by `mountAiAgentShells()` after the dynamic script load
skips inline `<script>` execution.

## Limits arithmetic

`aiagent/limits/concurrent_turns` is a per-store slot count: `Model\Limits\SlotLock`
refuses a new turn once that many are already streaming for the store, and the
caller gets a `busy` event with a retry-after instead of queueing behind the
others. `aiagent/limits/turn_wall_clock` is the longest a single turn is
allowed to hold its slot before the orchestrator forces a tool-less final
round.

The product of the two is the worst case load one store can put on the
PHP-FPM pool: `concurrent_turns x turn_wall_clock` seconds of workers held
open by streaming turns, at any moment, for that store. Size the pool's
`pm.max_children` (or the equivalent worker count) above that figure, summed
across every store on the pool, plus normal storefront traffic - not just
above `concurrent_turns` alone, since a worker is held for the whole turn,
not for one request-response cycle. Lowering `turn_wall_clock` shrinks the
worst case directly; raising `concurrent_turns` without checking the pool
size just moves the bottleneck from a `busy` event to FPM exhausting its
workers.

`turns_per_session`, `turns_per_session_window`, `turns_per_ip_minute` and
`max_tool_iterations` bound cost and abuse per session and per turn; they do
not affect the pool-sizing arithmetic above.

## Log

`var/log/aiagent.log`, a Monolog handler wired in `etc/di.xml`.

- INFO, one line per model call: `model call session=<12 hex digest>
  round=1 model=claude-sonnet-5 stop=tool_use input=812 cache_read=7420
  cache_write=0 output=240 elapsed_ms=2140`
- INFO on limits: `busy store=1 slots=4`, `limit session=<digest> kind=window`
- WARNING: tool failures (tool name and exception class, never the
  arguments), session version conflicts, retries
- DEBUG, only when `privacy/debug_log` is Yes: the request body as sent and
  the response as received. Off by default, because the request holds the
  whole cart, the customer profile and every fenced tool result; when it is
  on, the log file needs the same retention and access rules as the
  transcript table

The session id itself is never logged anywhere in this module: it is also the
request credential, so a log line naming it would leak a live session.

## Turn log

`aiagent_turn` records one row per completed turn: `session_id` (foreign key
to `aiagent_session`, cascades on delete), `store_id`, `turn_no` (the
session's own turn counter), `model_id`, `rounds` (model calls made during
the turn), the four usage fields (`input_tokens`, `output_tokens`,
`cache_creation_input_tokens`, `cache_read_input_tokens`), `duration_ms` and
`stop_reason`. The four usage fields come straight from the Messages API's
own `usage` object on each round, summed by `Orchestrator::streamTurn()`
into the same totals the `turn_complete` event carries; nothing here is
computed or estimated. `Model\Session\TurnLog` (`Api\Turn\TurnLogInterface`)
writes the row from the turn's `finally` block, after the session itself is
saved, through the plain `Model\Session\ResourceModel\Turn` resource; an
insert failure is caught and logged as a warning rather than breaking the
reply. `Test\Eval\InMemoryTurnLog` takes its place for `aiagent:eval:run`,
so an eval case never writes to the table. `Cron\Retention` deletes old
`aiagent_session` rows; the foreign key cascades their `aiagent_turn` rows
with them, so there is nothing extra to purge.

- `aiagent:usage:report [--days=<n>] [--store=<id>]` - reads `aiagent_turn`
  through the resource connection and prints two tables: turns and the four
  usage fields per day, then totals for the window with average tokens per
  turn and average tokens per session (distinct session ids with turns in
  the window). `--days` defaults to 30; `--store` restricts to one store id.

## Commands

- `aiagent:spike:stream [--store=<code>] [--message="..."] [--product=<id>]
  [--record=<name>] [--raw]` - by default builds a throwaway guest session
  (a synthetic quote id, an empty session state, nothing persisted) and runs
  one turn through the real orchestrator against the real client and
  backend, printing text deltas inline, a line per tool call and tool
  result, a line per UI component rendered, and the turn's usage and elapsed
  time. `--raw` streams `MessagesClientInterface` directly with a
  hard-coded request instead, to verify the SSE transport itself before an
  API key is wired to real orchestration. `--record=<name>` writes the raw
  SSE frames of every round to `Test/Fixtures/sse/<name>-round<n>.sse`
  (`--raw`) or `Test/Eval/recordings/<name>-round<n>.sse` (orchestrated).

- `aiagent:eval:run [--cases=<dir>] [--filter=<glob>] [--live]
  [--store=<code>] [--json]` - replays every `*.json` case under the cases
  directory (default `Test/Eval/cases`) through the orchestrator and grades
  the result. Without `--live` a case's `state.seen_products` is seeded
  straight into the session state and every tool call is served by an
  in-memory fake backend, so a run never reaches the real storefront; the
  model's turns are scripted from each case's `rounds` (or, when present,
  `Test/Eval/recordings/<id>-turn<n>.sse`). With `--live`, `state.cart` is
  seeded through the real backend and turns run against the real client and
  backend. Prints a table of id, priority, result and failed keys, or with
  `--json` the same as a JSON array; exits 1 when a critical or high
  priority case fails.

- `aiagent:session:purge [--older-than=<days>] [--customer=<id>] [--all]
  [--dry-run] [--force]` - deletes matching `aiagent_session` rows; messages
  cascade with them. Exactly one of `--older-than`, `--customer` or `--all`
  is required, `--all` also requires `--force`, and `--dry-run` prints the
  count without deleting anything.

## Extension points

The base module is a foundation: a store layer adds behaviour through
interfaces and DI pools, never through a preference on a base concrete
class. A concrete default that a store layer is expected to replace is an
interface with a final default implementation.

1. **Backend swap per method group.** `Api\StorefrontBackendInterface` stays
   one interface; `Model\Backend\MagentoStorefront` delegates to smaller
   interfaces per method group. Replace one with a preference:

   ```xml
   <preference for="MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface"
               type="Vendor\Store\Model\Backend\Provider\CatalogSearch"/>
   ```

   Two more method-group interfaces cover the catalog map and category
   search: `Api\Backend\CatalogMapProviderInterface::map(int $storeId, array
   $rootIds, int $depth): array` (default `Model\Backend\Provider\CategoryTree`)
   builds the node tree the static prompt's catalog map is formatted from,
   and `Api\Backend\CategorySearchProviderInterface::search(SessionContext
   $ctx, string $keywords, int $limit): array` (default
   `Model\Backend\Provider\CategoryNameSearch`) backs the `search_categories`
   tool. Both replace the same way:

   ```xml
   <preference for="MageOS\ClaudeConsumerAgent\Api\Backend\CatalogMapProviderInterface"
               type="Vendor\Store\Model\Backend\Provider\CatalogMap"/>
   ```

   A fourth, `Api\Backend\BestsellerRankInterface::rank(array $productIds,
   int $storeId): array` (default `Model\Backend\Provider\AggregatedBestsellerRank`),
   returns a set of product ids reordered by units sold; `FulltextSearch`
   calls it for `filters.sort = best_sellers`. A store layer with its own
   sales aggregation replaces it the same way:

   ```xml
   <preference for="MageOS\ClaudeConsumerAgent\Api\Backend\BestsellerRankInterface"
               type="Vendor\Store\Model\Backend\Provider\BestsellerRank"/>
   ```

2. **Tool registry as a DI pool.** `Api\Tool\ToolProviderInterface` items are
   pooled on `Model\Agent\Tool\Registry`, sorted after the built-ins by sort
   order then name:

   ```xml
   <type name="MageOS\ClaudeConsumerAgent\Model\Agent\Tool\Registry">
       <arguments>
           <argument name="providers" xsi:type="array">
               <item name="store" xsi:type="object">Vendor\Store\Model\Tool\StoreToolProvider</item>
           </argument>
       </arguments>
   </type>
   ```

3. **Presentation extensions as a DI pool.** `Api\Presentation\PresentationExtensionInterface`
   items are pooled on `Model\Agent\Presentation\Registry`; a name that
   collides with a built-in throws at construction:

   ```xml
   <type name="MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Registry">
       <arguments>
           <argument name="extensions" xsi:type="array">
               <item name="store_card" xsi:type="object">Vendor\Store\Model\Presentation\StoreCard</item>
           </argument>
       </arguments>
   </type>
   ```

4. **Prompt and lexicon extension.** `Model\Agent\Lexicon` and
   `Model\Agent\Skill\Loader` take array arguments merged with the admin
   fields and the built-in skill directory:

   ```xml
   <type name="MageOS\ClaudeConsumerAgent\Model\Agent\Lexicon">
       <arguments>
           <argument name="additionalPolicyTerms" xsi:type="array">
               <item name="0" xsi:type="string">warranty claim</item>
           </argument>
       </arguments>
   </type>
   ```

5. **Cart write hook.** `Api\Cart\BuyRequestBuilderInterface` builds the
   `DataObject` `Quote::addProduct()` receives; replace it or decorate it
   with a plugin:

   ```xml
   <preference for="MageOS\ClaudeConsumerAgent\Api\Cart\BuyRequestBuilderInterface"
               type="Vendor\Store\Model\Cart\BuyRequestBuilder"/>
   ```

6. **Theme templates.** Every surface and card template resolves through the
   normal theme fallback; override by path in a child theme
   (`app/design/frontend/Vendor/theme/MageOS_ClaudeConsumerAgent/templates/...`)
   or point a block at a different template in layout XML. No di.xml example
   applies here.

7. **Config scope.** `Model\Config\StoreConfig::forStore()` (via `agent()`)
   already resolves every field at store view scope; a store layer adds
   fields the same way, under its own config section, and reads them with
   its own `ScopeConfigInterface` call rather than extending this section.

## Privacy

- The transcript tables hold full conversation content, including anything a
  fenced tool result carried; `aiagent/privacy/retention_days` bounds how
  long a session and its messages live, and `aiagent:session:purge` is the
  manual escape hatch
- `aiagent/privacy/show_ai_label` controls whether the storefront marks the
  assistant's replies as AI-generated; leaving it off does not change what
  is logged or stored, only what the customer sees
- `aiagent/privacy/debug_log` is off by default because turning it on writes
  the full request and response bodies, cart and profile included, to
  `var/log/aiagent.log` - treat that file with the same access rules as the
  transcript table whenever it is on
- The session id is the request credential for every turn, transcript and
  reset call; it is generated with `random_bytes`, never logged, and never
  derived from anything else about the customer

## Tests

- `Test/Unit` - no Magento bootstrap, no database; run with
  `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/MageOS/ClaudeConsumerAgent/Test/Unit`
- `Test/Integration` - the Magento integration test framework, fixtures
  under `Test/Integration/_files`; run with the project's integration test
  configuration
- `Test/Eval` - scripted conversations graded against expected tool calls,
  UI components and cart state; run with `bin/magento aiagent:eval:run`
