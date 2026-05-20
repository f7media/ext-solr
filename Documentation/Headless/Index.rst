.. _headless-mode:

=============
Headless Mode
=============

.. contents::
   :local:
   :depth: 2


What Is Headless Mode?
======================

EXT:solr's frontend layer — the Extbase plugin (``pi_results``, ``pi_search``,
``pi_frequentlySearched``), Fluid templates, and ``SearchUriBuilder`` — is
designed for traditional server-rendered TYPO3 pages. When you are building a
**custom or decoupled frontend** (AJAX-driven single-page applications, headless
TYPO3 with a separate JavaScript framework, stateful search extensions), you may
want to use EXT:solr purely as a search backend without loading any of that
rendering infrastructure.

Headless mode makes this possible by:

1. Skipping Extbase plugin registration for the three rendering plugins.
2. Registering a lightweight PSR-15 middleware (``SearchApiMiddleware``) that
   accepts JSON search requests and returns ``SearchResultSet`` data as JSON.
3. Formalising the search-service contract via ``SearchEndpointInterface`` so
   custom controllers can type-hint against it.


When Should You Use It?
=======================

Use headless mode when **all** of the following apply:

-  You have a custom frontend that issues AJAX requests and renders results
   itself (JavaScript, Twig, or another template engine).
-  You do not use EXT:solr's Fluid templates or ``SearchUriBuilder`` for any
   page on the site.
-  You want to avoid the overhead of Extbase plugin dispatching for routes that
   will never reach ``SearchController``.

Keep the default (non-headless) mode when:

-  You use EXT:solr's built-in Fluid templates on at least one page.
-  You extend ``SearchController`` in your own extension.
-  You render facet URIs with ``SearchUriBuilder`` from Fluid.

.. note::
   Headless mode is **opt-in** and per-extension-configuration (not per-site).
   Mixing headless and standard plugins on the same TYPO3 instance is not
   supported. If you need headless search for some pages and standard rendering
   for others, keep standard mode and call ``SearchResultSetService`` directly
   from a custom controller.


Architecture in Headless Mode
==============================

.. code-block:: text

   Browser / JS SPA
       │
       │  POST /api/solr/search  (JSON body)
       ▼
   SearchApiMiddleware  (PSR-15, registered when headless = 1)
       │  builds TypoScriptConfiguration from request
       │  calls ConnectionManager → Search
       │  calls SearchResultSetService::search(SearchRequest)
       ▼
   SearchResultSet  (serialised to JSON)
       │
       ▼
   Browser / JS SPA  (renders results, facets, pagination)

The indexing pipeline, ``IndexQueue``, backend modules, and all domain-layer
classes are identical in both modes. Only the HTTP frontend layer differs.


See Also
========

-  :ref:`headless-configuration` — TypoScript and TYPO3\_CONF\_VARS settings.
-  :ref:`headless-migration` — Migrating an existing installation.
