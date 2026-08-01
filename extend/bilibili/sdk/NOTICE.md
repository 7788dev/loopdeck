# Protocol Attribution Notice

The protocol paths, request fields, response semantics, signing rules, and
compatibility notes used by this PHP SDK were researched from:

- Project: `bilibili-API-collect`
- Original project: https://github.com/SocialSisterYi/bilibili-API-collect
- Referenced fork: https://github.com/rinnein/bilibili-API-collect
- Referenced commit: `cfc5fddcc8a94b74d91970bb5b4eaeb349addc47`
- License: Creative Commons Attribution-NonCommercial 4.0 International
- License text: https://creativecommons.org/licenses/by-nc/4.0/

Current login behavior was additionally corroborated from the following
sources on 2026-08-02:

- Bilibili desktop client 1.17.9, which loads Bilibili's hosted MiniLogin v2
  component: https://s1.hdslb.com/bfs/seed/jinkela/short/mini-login-v2/miniLogin.umd.min.js
- Bilibili Fawkes Android upgrade endpoint, which reported version `9.5.0` and
  build `9050300`: https://app.bilibili.com/x/v2/version/fawkes/upgrade
- Bili23 Downloader commit `84f487ffa5c87f0b2cf93e52731205f8b9d543e6`
  (GPL-3.0): https://github.com/ScottSloan/Bili23-Downloader
- Omniget commit `8927c92a390109fc2bf1c767c23f1cecce509f2f`
  (GPL-3.0): https://github.com/tonhowtf/omniget
- BiliCompose commit `54546ea49cc2bc0ea0101187e3f978712c1c5c63`
  (Apache-2.0): https://github.com/suzhelan/BiliCompose

Those additional projects and the hosted MiniLogin bundle were used to verify
publicly observable endpoint names, request fields, response shapes, and
version metadata. Their source code is not redistributed in this SDK.

This project transforms the referenced API documentation into a native PHP SDK
and project-specific workflows. The implementation, naming, validation, cookie
handling, and tests have been modified for this codebase.

The attribution and license notice must be retained with redistributed copies
of this SDK. The referenced material may be used only in accordance with CC
BY-NC 4.0, including its attribution and non-commercial requirements.

This is an unofficial integration. It is not affiliated with, sponsored by, or
endorsed by Bilibili. Remote APIs can change or be withdrawn without notice.
