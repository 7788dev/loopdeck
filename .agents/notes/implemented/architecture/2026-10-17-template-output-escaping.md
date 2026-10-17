# Agent Note: Template output escaping and JavaScript contexts

Status: implemented

## Problem

Templates render values from users, platform APIs, upstream task results and
SMTP errors. ThinkPHP's template engine auto-escapes `{$var}` with
`htmlentities` by default, but the application never declared that invariant,
so a future config or engine change silently removes it. HTML escaping also
does not protect values interpolated into an inline event handler or a
`javascript:` URL: the browser decodes entities before parsing JavaScript, so
a quote in an upstream nickname or account id can still break out of the JS
string. The account info pages additionally interpolated `user_id`/`mid`
directly into `<script>` object literals, and the reset page did the same for
the mail address and token.

## Decision

- `config/view.php` declares `default_filter => 'html_escape'` explicitly. The
  public payment page, payment-code list, account lists/info pages, notification
  delivery log, alert pages and Epic catalog are covered by one root setting
  instead of per-template filters. Operator settings rendered through
  `{:config()}` bypass that filter by design, so those outputs carry an explicit
  `html_escape()`.
- Dynamic values never enter inline event handlers or `javascript:` URLs.
  The markup carries `data-*` attributes and jQuery event delegation (with
  namespaced `.off()` to survive PJAX) reads them.
- Values that must stay inside a `<script>` block go through `js_string()`,
  and URL values go through `safe_http_url()`/`payment_qrcode_url()`.
- Layer dialog titles pass task names through `x.escapeHtml()` because the
  layer library inserts title strings as HTML.
- `safe_http_url()` rejects control characters, whitespace, quotes, angle
  brackets and backslashes before `FILTER_VALIDATE_URL`, because that filter
  accepts attribute-breaking quotes. Notification emails reject the same
  attribute-breaking characters.
- `tests/TemplateRenderingTest.php` renders the real templates with malicious
  fixtures against `config/view.php` and asserts both encoded output and the
  absence of dynamic inline handlers, raw script interpolation and unsafe
  Epic URLs.
- Compiled template caches persist in the runtime volume, and a config-only
  change does not invalidate them. `SiteTemplate` namespaces the cache path by
  the output filter, so an upgraded installation cannot keep serving a page
  compiled under the old filter.

## Alternatives considered

- Escape at every sink (`{:html_escape(...)}`): drifts as templates are added,
  and was the source of the original exposure risk. Rejected.
- Pre-sanitize at input only: upstream nicknames/avatars/messages are not
  under this site's character rules, so the render layer must still encode.
  Rejected as the sole control.
- Keep relying on the engine's implicit `htmlentities`: works today but is an
  invisible default; an engine upgrade could change the filter or flags without
  any local diff. Rejected.
- Add a strict CSP and drop output encoding: CSP is defense-in-depth, not a
  substitute, and inline scripts are still used by the theme. Rejected.

## Consequences

Numeric/static `{:` helpers and explicit `|raw` output remain unescaped by
design; new code must use `js_string()` for JS literals and `safe_http_url()`
for links. The rendering regression now fails if a dynamic inline handler,
raw script interpolation or unsafe Epic URL returns.
