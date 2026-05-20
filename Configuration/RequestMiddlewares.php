<?php

use ApacheSolrForTypo3\Solr\Headless\SearchApiMiddleware;
use ApacheSolrForTypo3\Solr\Middleware\PageIndexerInitialization;
use ApacheSolrForTypo3\Solr\Middleware\SolrRoutingMiddleware;
use ApacheSolrForTypo3\Solr\System\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @noinspection PhpFullyQualifiedNameUsageInspection */
$requestMiddlewares = [
    'apache-solr-for-typo3/page-indexer-initialization' => [
        'target' => PageIndexerInitialization::class,
        'before' => ['typo3/cms-frontend/tsfe', 'typo3/cms-frontend/authentication'],
        'after' => ['typo3/cms-core/normalized-params-attribute'],
    ],
];

$extensionConfiguration = GeneralUtility::makeInstance(
    ExtensionConfiguration::class,
);
if ($extensionConfiguration->getIsRouteEnhancerEnabled()) {
    $requestMiddlewares['apache-solr-for-typo3/solr-route-enhancer'] = [
        'target' => SolrRoutingMiddleware::class,
        'before' => [
            'typo3/cms-frontend/site',
        ],
    ];
}

// Register the headless JSON search API endpoint only when headless mode is active.
// In standard (non-headless) mode this middleware is never instantiated, keeping the
// default request pipeline unchanged for existing installations.
if ($extensionConfiguration->getIsHeadlessModeEnabled()) {
    $requestMiddlewares['apache-solr-for-typo3/headless-search-api'] = [
        'target' => SearchApiMiddleware::class,
        'after' => ['typo3/cms-frontend/prepare-tsfe-rendering'],
        'before' => ['typo3/cms-frontend/content-length-headers'],
    ];
}

return [
    'frontend' => $requestMiddlewares,
];
