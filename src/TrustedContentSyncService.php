<?php

namespace Drupal\ucb_trusted_content_discovery;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

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
    // TO DO: Remove logging
    $this->logger->notice('run() method called on TrustedContentSyncService');

    $config = $this->configFactory->get('ucb_trusted_content_discovery.sites');
    $sites = $config->get('sites') ?? [];

    if (empty($sites)) {
      $this->logger->warning('No sites configured in ucb_trusted_content_discovery.sites');
      return;
    }

    // TO DO : Need this for ddev, should work for prod but will need to test
    $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

    foreach ($sites as $site_name => $site_info) {
      $this->logger->notice('Syncing from site: @site', ['@site' => $site_name]);

      $base_url = $site_info['public'] ?? '';
      if ($isDdev && !empty($site_info['internal'])) {
        $base_url = $site_info['internal'];
        $this->logger->notice('Using internal URL for DDEV: @internal', ['@internal' => $base_url]);
      }

      if (empty($base_url)) {
        $this->logger->error('No valid URL defined for site: @site', ['@site' => $site_name]);
        continue;
      }
      // Endpoint
      $query = http_build_query([
        'include' => 'trust_topics,node_id,node_id.field_ucb_article_thumbnail,node_id.field_ucb_article_thumbnail.field_media_image',
        'fields[trust_metadata--trust_metadata]' => 'trust_role,trust_scope,trust_contact,trust_topics,node_id',
        'fields[taxonomy_term--trust_topics]' => 'name',
        'fields[node--basic_page]' => 'title,body',
        'fields[node--ucb_person]' => 'title,body',
        'fields[node--ucb_article]' => 'title,field_ucb_article_summary,field_ucb_article_thumbnail',
        'fields[media--image]' => 'field_media_image',
        'fields[file--file]' => 'uri,url',
      ]);

      $url = rtrim($base_url, '/') . '/jsonapi/trust_metadata/trust_metadata?' . $query;

      $this->logger->notice('Requesting URL: @url', ['@url' => $url]);

      try {
        $response = $this->httpClient->get($url, [
          'headers' => ['Accept' => 'application/json'],
          'verify' => !$isDdev,
        ]);
        //TO DO:  DEBUGGING START (remove later)
        $raw = (string) $response->getBody();
        file_put_contents('/tmp/trusted-content.json', $raw);

        $this->logger->notice('Full JSON response: @full', [
          '@full' => $raw,
        ]);

        $this->logger->notice('Response status code: @code', ['@code' => $response->getStatusCode()]);

        $json = json_decode($raw, true);
        $this->logger->debug('Decoded JSON: @json', ['@json' => print_r($json, true)]);

        //TO DO:  DEBUGGING END (remove later)
        if (!is_array($json) || !isset($json['data'])) {
          $this->logger->error('Invalid or missing "data" key in JSON response from @url', ['@url' => $url]);
          continue;
        }
        //TO DO:  DEBUGGING
        $this->logger->notice('Fetched @count items', ['@count' => count($json['data'])]);
        foreach ($json['data'] as $item) {
          $this->logger->notice('📥 Processing item ID: @id', ['@id' => $item['id'] ?? 'unknown']);
          $this->saveEntity($item, $json['included'] ?? [], $site_name);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('💥 Error: @msg', ['@msg' => $e->getMessage()]);
      }
    }
  }

  protected function saveEntity(array $item, array $included, string $site): void {
    if (empty($item['id']) || empty($item['attributes'])) {
      $this->logger->warning('Skipping item with missing ID or attributes.');
      return;
    }

    $uuid = $item['id'];
    $attributes = $item['attributes'];
    $relationships = $item['relationships'] ?? [];

    $nodeRef = $relationships['node_id']['data'] ?? null;
    $nodeId = $nodeRef['id'] ?? null;
    $nodeType = $nodeRef['type'] ?? null;
    // TO DO : Remove debugging
    $this->logger->info('Processing item @uuid with node @type:@id', [
      '@uuid' => $uuid,
      '@type' => $nodeType,
      '@id' => $nodeId,
    ]);

    $relatedNode = $this->findIncludedById($included, $nodeType, $nodeId);
    if (!$relatedNode) {
      $this->logger->warning('Related node not found for @type:@id', ['@type' => $nodeType, '@id' => $nodeId]);
    }

    $nodeAttrs = $relatedNode['attributes'] ?? [];
    $title = $nodeAttrs['title'] ?? 'Untitled';
    $summary = '';

    switch ($nodeType) {
      case 'node--ucb_article':
        $summary = $nodeAttrs['field_ucb_article_summary'] ?? '';
        break;
      case 'node--ucb_person':
      case 'node--basic_page':
        $summary = $nodeAttrs['body']['summary'] ?? '';
        break;
    }
// TO DO: Fix linking topics to pre-set taxonomy
    $topicNames = [];
    foreach ($relationships['trust_topics']['data'] ?? [] as $topicRef) {
      $topic = $this->findIncludedById($included, $topicRef['type'], $topicRef['id']);
      if (!empty($topic['attributes']['name'])) {
        $topicNames[] = $topic['attributes']['name'];
      }
    }
// Save as a content reference entity, TO DO: Update existing by UUID
    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');
    $entities = $storage->loadByProperties(['remote_uuid' => $uuid]);
    $entity = reset($entities) ?: $storage->create(['remote_uuid' => $uuid]);

    $entity->set('title', $title);
    $entity->set('summary', $summary);
    $entity->set('trust_role', $attributes['trust_role'] ?? '');
    $entity->set('trust_scope', $attributes['trust_scope'] ?? '');
    $entity->set('remote_type', $nodeType);
    $entity->set('source_site', $site);
    $entity->set('source_url', $relatedNode['links']['self']['href'] ?? '');
    $entity->set('jsonapi_payload', json_encode($item));
    $entity->set('last_fetched', \Drupal::time()->getRequestTime());
    $entity->set('topic_names', json_encode($topicNames));

    $entity->save();
// TO DO: Remove logging
    $this->logger->notice('Saved entity @uuid with title: @title', [
      '@uuid' => $uuid,
      '@title' => $title,
    ]);
  }

  protected function findIncludedById(array $included, string $type, string $id): ?array {
    foreach ($included as $entry) {
      if ($entry['type'] === $type && $entry['id'] === $id) {
        return $entry;
      }
    }
    return null;
  }
}
