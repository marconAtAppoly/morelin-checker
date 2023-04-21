# morelin-checker
Checks **MO**del **REL**ationship **IN**dex

It crawls the app folder recursively. Then crawls those models to check for relationship methods.
Checks if the foreign key in the relationship is indexed. Finally it spits out the truth about relationhips at the end.

# Usage
**D' Basic**

`php artisan appoly:morelin-check`

Checks all files in app directory.


**D' Basic plus**

`php artisan appoly:morelin-check  --dir=Models`

Specifies a folder to check, this is recomended if Models directory exist.


**Show'em all**

`php artisan appoly:morelin-check --show-all`

Shows all the relactionship check result even those foreign keys that are indexed.


**D' Logger**

`php artisan appoly:morelin-check --show-others`

Shows all other information like models that are being processed or errors encountered.


**D' Ol' of d' abover**

`php artisan appoly:morelin-check --show-all --show-others --dir=Models`

You can use all the options or just two of them at once and in any order if needed.


# Compatibility
This was developed and tested using **laravel 9**.

However the developer suspects and prays it will also work in lower versions. If not .. meh :) jk just send me a pm

May the creator give you strength adventurer.
