.. _headless-migration:

==================
Headless Migration
==================

.. contents::
   :local:
   :depth: 2


Existing Installations (Standard → Headless)
============================================

If you currently use EXT:solr's Fluid-based frontend plugin and want to migrate
to a custom headless frontend, follow these steps.

Step 1 — Remove the content element
------------------------------------

Remove all ``tx_solr_pi_results``, ``tx_solr_pi_search``, and
``tx_solr_pi_frequentlySearched`` content elements from your pages. The content
elements will no longer be rendered once headless mode is active.

.. warning::
   Do **not** delete the plugin registrations from EXT:solr itself. They are
   simply skipped at bootstrap time when ``headless = 1``. Removing the class
   files would break upgrades.

Step 2 — Enable headless mode
------------------------------

In the TYPO3 Install Tool, navigate to
:guilabel:`Settings > Extension Configuration > solr` and set **Headless mode**
to ``1``. Or set it programmatically:

.. code-block:: php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['solr']['headless'] = 1;

Step 3 — Add TypoScript constants
----------------------------------

.. code-block:: typoscript

   plugin.tx_solr.features.headless = 1
   plugin.tx_solr.features.headless.apiPath = /api/solr/search

Step 4 — Remove the Route Enhancer (if present)
-------------------------------------------------

If you previously used EXT:solr's Route Enhancer, remove the entry from your
site configuration YAML and from :file:`ext_conf_template.txt` settings. The
headless frontend manages URL state itself.

Step 5 — Build your custom frontend
-------------------------------------

Your JavaScript or custom PHP controller should:

1. Send ``POST /api/solr/search`` with a JSON body containing ``q``, ``filter``,
   ``sort``, and ``page`` keys.
2. Render the ``documents``, ``facets``, and ``sortings`` from the JSON response.
3. Handle 503 gracefully (Solr unavailable).

See :ref:`headless-configuration` for the full request/response schema.

Step 6 — Verify indexing still works
--------------------------------------

Indexing is **unaffected** by headless mode. Confirm that:

-  The TYPO3 Scheduler tasks (``IndexQueueWorkerTask``, ``ReIndexTask``) are
   still configured and running.
-  The EXT:solr backend module shows a healthy connection and a non-empty
   index queue.


Custom Frontend Extension (Greenfield)
=======================================

If you are building a new headless frontend from scratch alongside EXT:solr:

1. Set ``headless = 1`` from day one in extension configuration and TypoScript.
2. Implement a JavaScript or PHP frontend that calls ``/api/solr/search``.
3. Type-hint against ``SearchEndpointInterface`` in any PHP controller that calls
   EXT:solr directly. See :ref:`headless-configuration` for the injection pattern.
4. Do **not** add ``pi_results`` content elements to your pages; they are not
   registered in headless mode.


Reverting to Standard Mode
===========================

To revert to the standard Extbase plugin:

1. Set ``headless = 0`` in extension configuration and TypoScript.
2. Re-add ``tx_solr_pi_results`` content elements to your pages.
3. Re-add the Route Enhancer to your site configuration if you use facet URLs.

No database changes are required; headless mode only affects the PHP bootstrap
and middleware stack.
