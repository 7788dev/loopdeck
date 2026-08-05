# Runtime asset notice

`../runtime/vendor/bdms.js` is a snapshot of the BDMS browser asset used by the
local protocol fixture.

- SHA-256: `d211c62a7ab5eb5d8bc2a0bde54657999fcbaa5dc869964c46dd79cc0865895d`
- Captured: 2026-08-05
- Upstream version: `1.0.1.19-fix.01`
- Upstream raw SHA-256: `a0e46f84476d63a1fa57bdcb47223e0e545c6ce0c3dacdd2818180dfa087909e`
- Runtime use: local in-memory VM URL signing only

`../runtime/vendor/dtrait.js` is the matching DTrait core used to construct the
encrypted request-environment header inside the same isolated VM.

- SHA-256: `af6984d4fdf37eb38be717ec0601528a477a070a646d3f4ec2a87e8eadac74d6`
- Captured: 2026-08-05
- Upstream version: `1.0.0.16`
- Upstream raw SHA-256: `cf35e851627219261e00c1362b40611ad9cbdc9ea5c394ce2cc6561450c969ca`
- Runtime use: local in-memory VM environment-header generation only

The checked-in runtimes contain no cookies, QR tokens, `msToken` values,
challenge responses, or request captures. Those artifacts remain under the
ignored `.codex-temp/` directory.

`../../../public/static/js/douyin-captcha-runtime.js` is the matching browser
renderer used only when the upstream response includes interactive verification
data.

- SHA-256: `f5c075614a54fd57ac13f84a2e6d5e2952250e17a7b91a730b735d63227ddc3a`
- Upstream version: `4.0.28`
- Upstream raw SHA-256: `b4e97d9734a5dded4de3b64f94ba2dd77610492edd452f9b3326a112ef144c14`
