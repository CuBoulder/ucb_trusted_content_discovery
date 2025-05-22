<?php

namespace Drupal\ucb_trusted_content_discovery\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service to sync trusted content from external sites.
 */
class TrustedContentSync {

  protected ClientInterface $httpClient;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  public function __construct(ClientInterface $httpClient, EntityTypeManagerInterface $entityTypeManager, LoggerInterface $logger) {
    $this->httpClient = $httpClient;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
  }

  // TO DO: Will need to wire up to config sites.yml
  public function run(): void {
    $sites = [
      'https://trusted-site.ddev.site',
      // Add more base URLs here
    ];

    foreach ($sites as $site) {
      $url = $site . '/api/trust-schema/syndicated-nodes';
      try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['Accept' => 'application/json'],
        // TO DO: Change for Prod
        'verify' => FALSE,
      ]);
        $data = json_decode($response->getBody(), TRUE);
        if (!empty($data['data'])) {
          foreach ($data['data'] as $item) {
            $this->importItem($item);
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Sync failed for @url: @message', [
          '@url' => $url,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  protected function importItem(array $item): void {
    $id = $item['id'];
    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');

    $entities = $storage->loadByProperties(['id' => $id]);
    $entity = reset($entities) ?: $storage->create(['id' => $id]);

    $attrs = $item['attributes'];
    $entity->set('title', $attrs['title'] ?? '');
    $entity->set('summary', $attrs['summary'] ?? '');
    $entity->set('trust_role', $attrs['trust_role'] ?? '');
    $entity->set('trust_scope', $attrs['trust_scope'] ?? '');
    $entity->set('source_site', parse_url($attrs['url'], PHP_URL_HOST));
    $entity->set('source_url', $attrs['url']);
    $entity->set('jsonapi_payload', json_encode($item));
    $entity->set('last_fetched', \Drupal::time()->getRequestTime());

    $entity->save();
  }

}
