.. _headless-configuration:

======================
Headless Configuration
======================

.. contents::
   :local:
   :depth: 2


Enabling Headless Mode
======================

Two settings must be configured together. Because EXT:solr's plugin registration
happens at bootstrap time (before TypoScript is loaded), the primary toggle lives
in the TYPO3 extension configuration. The TypoScript constant mirrors it for
features that are evaluated at request time.


Extension Configuration (required)
-----------------------------------

In the TYPO3 Install Tool under :guilabel:`Settings > Extension Configuration > solr`,
enable **Headless mode**:

.. code-block:: php
   :caption: Alternatively set programmatically, e.g. in system configuration:

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['solr']['headless'] = 1;

Setting this to ``1`` does the following at bootstrap time:

-  Skips ``ExtensionUtility::configurePlugin()`` for ``pi_results``,
   ``pi_search``, and ``pi_frequentlySearched``.
-  Registers ``SearchApiMiddleware`` in the PSR-15 frontend middleware stack.

The ``pi_suggest`` plugin is **always** registered regardless of this flag,
because autocomplete is useful in headless setups too.


TypoScript Constants (recommended)
------------------------------------

Add the following to your site's TypoScript constants to activate headless
features at the TypoScript level and to configure the API path:

.. code-block:: typoscript
   :caption: TypoScript Constants

   plugin.tx_solr.features.headless = 1
   plugin.tx_solr.features.headless.apiPath = /api/solr/search

``features.headless``
   Mirrors the extension configuration flag. Used by TypoScript conditionals
   and the SearchApiMiddleware to confirm headless mode is active.

``features.headless.apiPath``
   The URL path at which ``SearchApiMiddleware`` listens. Defaults to
   ``/api/solr/search``. Must be a path on the same TYPO3 instance.
   Change this if ``/api/solr/search`` conflicts with another route.


.. note::
   Because TYPO3 evaluates ``ext_conf_template.txt`` before TypoScript, both
   settings are needed. The extension configuration controls bootstrap-time
   behaviour; the TypoScript constant controls runtime behaviour.


Route Enhancer Warning
======================

EXT:solr's Route Enhancer (``SolrFacetMaskAndCombineEnhancer``) is configured
in site YAML files and cannot be conditionally disabled at runtime. In headless
mode the facet URL encoding it provides is not used because the custom frontend
manages URL state itself. If a ``SolrFacetMaskAndCombineEnhancer`` entry is
present in your site configuration while ``headless = 1``, EXT:solr logs a
warning to the ``solr`` log channel.

To suppress the warning, remove the enhancer from your site YAML:

.. code-block:: yaml
   :caption: config/sites/<identifier>/config.yaml — remove this block

   routeEnhancers:
     SolrSearch:
       type: SolrFacetMaskAndCombineEnhancer
       # ... remove entirely when headless = 1


SearchApiMiddleware — JSON API
==============================

When headless mode is enabled, ``SearchApiMiddleware`` handles
``POST <apiPath>`` requests.

Request Format
--------------

.. code-block:: http

   POST /api/solr/search HTTP/1.1
   Content-Type: application/json
   Origin: https://www.example.com

   {
     "q": "search term",
     "filter": ["category:news", "author:alice"],
     "sort": "score desc",
     "page": 1
   }

All body fields are optional. Field names match the EXT:solr plugin namespace
parameters (``tx_solr[q]``, ``tx_solr[filter]``, etc.) without the prefix.

Security
--------

The middleware validates the ``Origin`` header against the request host
(same-origin check). Requests without an ``Origin`` header (e.g., server-side
calls, ``curl``) are allowed through. Cross-origin requests from a different
host, scheme, or port receive a ``403 Forbidden`` JSON response.

Response Format
---------------

.. code-block:: json

   {
     "allResultCount": 42,
     "hasSearched": true,
     "usedPage": 1,
     "usedResultsPerPage": 10,
     "usedQuery": "search term",
     "isAutoCorrected": false,
     "initialQueryString": "",
     "correctedQueryString": "",
     "documents": [
       { "id": "1/page/42", "title": "Example Page", "url": "/example", "score": 1.5 }
     ],
     "facets": [
       { "name": "category", "field": "category_stringM", "label": "Category", "isUsed": false, "isAvailable": true }
     ],
     "sortings": [
       { "name": "relevance", "field": "score", "label": "Relevance", "isSelected": true, "direction": "desc" }
     ]
   }

Error Responses
---------------

+--------+-------------------------------------------+
| Status | Meaning                                   |
+========+===========================================+
| 400    | Request body is not valid JSON            |
+--------+-------------------------------------------+
| 403    | Origin header does not match request host |
+--------+-------------------------------------------+
| 503    | Solr server is not reachable              |
+--------+-------------------------------------------+


SearchEndpointInterface — Custom Controllers
============================================

``SearchEndpointInterface`` (``ApacheSolrForTypo3\Solr\Headless\SearchEndpointInterface``)
formalises the contract that ``SearchResultSetService`` already satisfies
informally. Use it as a type hint in custom controllers to stay decoupled from
the concrete class:

.. code-block:: php
   :caption: Classes/Controller/MySearchController.php

   use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSetService;
   use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequestBuilder;
   use ApacheSolrForTypo3\Solr\Headless\SearchEndpointInterface;

   class MySearchController extends ActionController
   {
       public function searchAction(): ResponseInterface
       {
           $typoScriptConfiguration = /* build from request, same as AbstractBaseController */;
           $search = GeneralUtility::makeInstance(Search::class, $solrConnection);

           /** @var SearchEndpointInterface $searchService */
           $searchService = GeneralUtility::makeInstance(
               SearchResultSetService::class,
               $typoScriptConfiguration,
               $search,
           );

           $searchRequest = GeneralUtility::makeInstance(SearchRequestBuilder::class, $typoScriptConfiguration)
               ->buildForSearch($this->request->getArguments(), $pageId, $languageId);

           $resultSet = $searchService->search($searchRequest);
           // render $resultSet with your own template engine
       }
   }

.. tip::
   ``SearchResultSetService`` requires per-request runtime arguments
   (``TypoScriptConfiguration``, ``Search``) that cannot be provided by the DI
   container at compile time. Always instantiate it via
   ``GeneralUtility::makeInstance()`` as shown above, then type-hint the
   returned instance as ``SearchEndpointInterface`` for clarity.
