<?php

namespace Drupal\ucb_trusted_content_discovery;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for syncing trusted content from external sites.
 */
class TrustedContentSyncService {

  protected $httpClient;
  protected $configFactory;
  protected $entityTypeManager;
  protected $logger;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
  }

  public function run() {
    $config = $this->configFactory->get('ucb_trusted_content_discovery.sites');
    $sites = $config->get('sites') ?? [];

    foreach ($sites as $site) {
      $url = rtrim($site, '/') . '/api/trust-schema/syndicated-nodes';
      try {
        $response = $this->httpClient->get($url, ['headers' => ['Accept' => 'application/json']]);
        $json = json_decode($response->getBody(), true);

        if (!empty($json['data']) && is_array($json['data'])) {
          foreach ($json['data'] as $item) {
            $this->saveEntity($item, $site);
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Fetch error from @url: @message', [
          '@url' => $url,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  protected function saveEntity(array $item, string $site) {
    if (empty($item['id']) || empty($item['attributes'])) {
      return;
    }

    $attributes = $item['attributes'];
    $uuid = $site . '::' . $item['id'];

    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');
    $entities = $storage->loadByProperties(['source_url' => $attributes['url']]);
    $entity = reset($entities) ?: $storage->create();

    $entity->set('title', $attributes['title'] ?? 'Untitled');
    $entity->set('summary', $attributes['summary'] ?? '');
    $entity->set('trust_scope', $attributes['trust_scope'] ?? '');
    $entity->set('trust_role', $attributes['trust_role'] ?? '');
    $entity->set('source_site', $site);
    $entity->set('source_url', $attributes['url'] ?? '');
    $entity->set('jsonapi_payload', json_encode($item));
    $entity->set('last_fetched', \Drupal::time()->getRequestTime());

    $entity->save();
  }
}
