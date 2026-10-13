# Bundled spreadsheet dependency

The production vendor tree is generated from `composer.json` and `composer.lock` with Composer 2 using PHP 7.4 as the configured platform:

```text
php composer.phar install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-zip
php composer.phar audit --locked --no-dev
```

PhpSpreadsheet is pinned to 1.30.7 so the CSV, XLS and XLSX import/export APIs remain compatible with PHP 7.4 while incorporating the maintained 1.x security fixes. Runtime hosts must provide the extensions declared by the generated lock file; the ignored extension flags are for dependency assembly in the isolated build environment only.

The bundled `Writer/ZipStream3.php` carries a narrow downstream compatibility patch. The upstream 1.30.7 adapter uses PHP 8 named-argument syntax in an optional ZipStream 3 branch, which makes the PhpSpreadsheet writer tree fail a PHP 7.4 syntax scan even though Composer selects ZipStream 2. The patch resolves the same ZipStream 3 constructor parameters by reflection and preserves the upstream values. The full-tree PHP 7.4 lint recipe excludes Symfony's intentionally PHP-8-only `polyfill-mbstring/bootstrap80.php`; Composer conditionally loads its PHP 7 bootstrap instead on supported PHP 7.4 hosts.

Package names, exact versions, source references and licences are recorded in `composer.lock` and `vendor/composer/installed.json`. PhpSpreadsheet and its production transitive dependencies use GPL-compatible MIT or LGPL-2.1-or-later licences; their package licence files are retained in the vendor tree.

## UPCP namespace isolation (AID-115 / AID-116)

The generated runtime now uses `EwdUpcpVendor` for PhpSpreadsheet and its dependencies, with no global PhpSpreadsheet aliases. Composer's loader suffix remains `EwdUpcpSpreadsheet1307`; eager-loaded file identifiers are also isolated. Runtime origin and exact-version checks remain required. The dependency versions and source references in the lock file are unchanged.

Rebuild in an owner-compatible development container with Composer and PHP-Scoper **0.18.4** installed. Set `UPCP_PHP_SCOPER` to its executable and run `sh build/release2/scope-spreadsheet.sh`. The script assembles only locked production dependencies, scopes the runtime, regenerates the autoloader, isolates eager-file IDs, reapplies the existing ZipStream3 PHP-7.4 adapter, applies the PHP-8.5 column-letter iteration compatibility changes, and normalizes generated whitespace without changing executable tokens or string data. Build tools are not shipped.

The column-letter patch uses existing `Coordinate` conversions in Worksheet and CSV/XLSX readers to avoid deprecated string increment operations, including the Z/AA boundary. The pinned ZipStream 2 implementation is still selected on PHP 7.4. Generated PHP is verified on 7.4 and 8.4, and strict CSV/XLS/XLSX round trips are verified on 7.4, 8.4 and 8.5 with another family's real global library loaded in both orders.
