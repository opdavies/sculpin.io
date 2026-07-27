Sculpin Website
===============

This repository contains (almost) everything that makes up
[sculpin.io](http://sculpin.io).

Powered by [Sculpin](https://github.com/sculpin/sculpin). =)

&copy; Dragonfly Development Inc.


Build
-----

    composer install
    ./vendor/bin/sculpin generate --watch --server

Your newly generated clone of [sculpin.io](https://sculpin.io) is now
accessible at `http://localhost:8000/`.

Submit & Publish
----------------

The recommended process for submitting a PR to the site is to first
commit your changes to a branch on your fork, and then run the command
`composer publish` to update the `docs/` folder that is used for GitHub
hosting.  Commit the changes to `docs/` to the same branch, and then
submit the PR using the normal GitHub flow.

It's not mandatory to follow this recommendation, but it does help make
it easier on maintainers. They'll be able to click the "Merge" button
and the changes will be deployed immediately.

Bundles
-------

The Bundles list on the home page and the community page is rendered
from `app/config/bundles.yml`.

`scripts/fetch-bundles.php` discovers bundles on GitHub and refreshes
their metadata from GitHub and Packagist:

    GITHUB_TOKEN=... composer fetch-bundles

A repository is discovered if it has the `sculpin-bundle` topic, or if
its name contains both "sculpin" and "bundle". The token is optional,
but without one the GitHub Search API allows only 10 requests per
minute.

The script never publishes anything on its own. New finds are written to
the `discovered` list, which is not rendered; a maintainer moves the
entries that belong on the site into `bundles`, and adds anything that
should never be listed to `ignored`.

To add a bundle by hand, add an entry to `bundles` with a `name`,
`repository` and `description`, then run the script to fill in the rest.
It refreshes `description`, `version`, `updated`, `stars`, `archived`
and `abandoned` on every run, keeps both lists in alphabetical order, and
leaves everything else alone.

What gets rendered is decided in
`source/_views/includes/bundles.html`, not by the file order: bundles
marked abandoned on Packagist are skipped, and the rest are listed most
recently updated first.

Notes
-----

* To add an item to the Documentation sidebar, modify the YAML in
  `app/config/sculpin_site.yml` to add the entry.