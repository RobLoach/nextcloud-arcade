# Translations

Nextcloud reads translations from `l10n/<language>.json` in this folder, and
falls back to the English written in the source when a string is missing.

`npm run l10n:extract` collects the translatable strings into
`translationfiles/templates/arcade.pot`, which is what a translation
platform works from. Only literal strings are collected: a string passed as
a variable cannot be translated, so the app never does that.
