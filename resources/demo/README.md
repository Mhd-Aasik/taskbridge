# Original demonstration artwork

The eight WebP covers in `covers/` are original geometric interface illustrations
created for TaskBridge on 24 October 2026. They depict a hotel booking interface,
restaurant menu, retailer catalogue, appointment calendar, tea packaging, student
task board, portfolio and reporting dashboard. They contain no stock photography,
remote image links, personal data or third-party logos.

`generate-covers.php` is their PHP/GD source. From the repository root:

```powershell
php resources/demo/generate-covers.php
```

This replaces only the eight source artwork files; it does not upload images,
change database records or replace already stored covers. Each source is a
1200 × 760 landscape WebP. The normal project-cover action validates and
normalizes separate private copies. The artwork is deliberately simple enough
for card/mobile cropping, with little embedded text.

The generator, catalogue and artwork are provided under the repository's MIT
license terms; see [LICENSE.txt](LICENSE.txt). The uploaded TB monogram and the
separately licensed Instrument Sans font are not part of this artwork grant.

See [demonstration setup](../../docs/demonstration-data.md) before running any
command that changes local records.
