# Bundled spreadsheet dependency

The production vendor tree is generated from `composer.json` and `composer.lock` with Composer 2 using PHP 7.4 as the configured platform:

```text
php composer.phar install --no-dev --prefer-dist --optimize-autoloader --ignore-platform-req=ext-gd --ignore-platform-req=ext-fileinfo --ignore-platform-req=ext-mbstring --ignore-platform-req=ext-zip
php composer.phar audit --locked --no-dev
```

PhpSpreadsheet is pinned to 1.30.6 so the CSV, XLS and XLSX import/export APIs remain compatible with PHP 7.4 while incorporating the maintained 1.x security fixes. Runtime hosts must provide the extensions declared by the generated lock file; the ignored extension flags are for dependency assembly in the isolated build environment only.

The bundled `Writer/ZipStream3.php` carries a narrow downstream compatibility patch. The upstream 1.30.6 adapter uses PHP 8 named-argument syntax in an optional ZipStream 3 branch, which makes the PhpSpreadsheet writer tree fail a PHP 7.4 syntax scan even though Composer selects ZipStream 2. The patch resolves the same ZipStream 3 constructor parameters by reflection and preserves the upstream values. The full-tree PHP 7.4 lint recipe excludes Symfony's intentionally PHP-8-only `polyfill-mbstring/bootstrap80.php`; Composer conditionally loads its PHP 7 bootstrap instead on supported PHP 7.4 hosts.

Package names, exact versions, source references and licences are recorded in `composer.lock` and `vendor/composer/installed.json`. PhpSpreadsheet and its production transitive dependencies use GPL-compatible MIT or LGPL-2.1-or-later licences; their package licence files are retained in the vendor tree.
