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
    $this->logger->notice('run() method called on TrustedContentSyncService');

    $config = $this->configFactory->get('ucb_trusted_content_discovery.sites');
    $sites = $config->get('sites') ?? [];

    if (empty($sites)) {
      $this->logger->warning('No sites configured in ucb_trusted_content_discovery.sites');
      return;
    }

    $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

    foreach ($sites as $site_name => $site_info) {
      $this->logger->notice('Syncing from site: @site', ['@site' => $site_name]);

      $publicBase = rtrim($site_info['public'] ?? '', '/');
      $internalBase = rtrim($site_info['internal'] ?? '', '/');
      $base_url = $publicBase;

      if ($isDdev && !empty($internalBase)) {
        $base_url = $internalBase;
        $this->logger->notice('Using internal URL for DDEV: @internal', ['@internal' => $internalBase]);
      }

      if (empty($base_url)) {
        $this->logger->error('No valid URL defined for site: @site', ['@site' => $site_name]);
        continue;
      }
    // TO DO - filter by enabled only -
    $query = http_build_query([
      'include' => 'trust_topics,node_id,node_id.field_ucb_article_thumbnail,node_id.field_ucb_article_thumbnail.field_media_image,node_id.field_ucb_person_photo,node_id.field_ucb_person_photo.field_media_image',
      'fields[trust_metadata--trust_metadata]' => 'trust_role,trust_scope,trust_contact,trust_topics,node_id,trust_syndication_enabled',
      'fields[taxonomy_term--trust_topics]' => 'name',
      'fields[node--basic_page]' => 'title,body,changed,nid',
      'fields[node--ucb_person]' => 'title,body,changed,field_ucb_person_photo,nid',
      'fields[node--ucb_article]' => 'title,field_ucb_article_summary,field_ucb_article_thumbnail,changed,nid',
      'fields[media--image]' => 'field_media_image',
      'fields[file--file]' => 'uri,url',
      'sort' => '-node_id.changed',
      'filter[trust_syndication_enabled][value]' => '1'
    ]);


      $url = rtrim($base_url, '/') . '/jsonapi/trust_metadata/trust_metadata?' . $query;
      $this->logger->notice('Requesting URL: @url', ['@url' => $url]);

      try {
        $response = $this->httpClient->get($url, [
          'headers' => [
            'Accept' => 'application/json',
            // Add this if using internalBase
            'Host' => parse_url($publicBase, PHP_URL_HOST),
          ],
          'verify' => !$isDdev,
        ]);

        $this->logger->notice('Setting Host header to: @host', ['@host' => parse_url($publicBase, PHP_URL_HOST)]);


        $raw = (string) $response->getBody();
        file_put_contents('/tmp/trusted-content.json', $raw);

        $this->logger->notice('Full JSON response: @full', ['@full' => $raw]);
        $this->logger->notice('Response status code: @code', ['@code' => $response->getStatusCode()]);

        $json = json_decode($raw, true);
        $this->logger->debug('Decoded JSON: @json', ['@json' => print_r($json, true)]);

        if (!is_array($json) || !isset($json['data'])) {
          $this->logger->error('Invalid or missing "data" key in JSON response from @url', ['@url' => $url]);
          continue;
        }

        $this->logger->notice('Fetched @count items', ['@count' => count($json['data'])]);

        foreach ($json['data'] as $item) {
          $this->logger->notice('Processing item ID: @id', ['@id' => $item['id'] ?? 'unknown']);
          $this->saveEntity($item, $json['included'] ?? [], $site_name, $internalBase, $publicBase);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Error: @msg', ['@msg' => $e->getMessage()]);
      }
    }
  }

  protected function saveEntity(array $item, array $included, string $site, string $internalBase, string $publicBase): void {
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

    $relatedNode = $this->findIncludedById($included, $nodeType, $nodeId);
    if (!$relatedNode || empty($relatedNode['attributes'])) {
      $this->logger->warning('Related node not found or missing attributes for @type:@id', ['@type' => $nodeType, '@id' => $nodeId]);
      return;
    }

    $nodeAttrs = $relatedNode['attributes'];
    $remoteChanged = strtotime($nodeAttrs['changed'] ?? '0');

    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');
    $entities = $storage->loadByProperties(['uuid' => $uuid]);
    $entity = reset($entities);

    if ($entity) {
      $localLastFetched = (int) $entity->get('last_fetched')->value;
      if ($remoteChanged <= $localLastFetched) {
        $this->logger->notice('Skipped entity @uuid, already up-to-date.', ['@uuid' => $uuid]);
        return;
      }
    }
    else {
      $entity = $storage->create(['uuid' => $uuid]);
    }

    $title = $nodeAttrs['title'] ?? 'Untitled';
    $remoteNid = $nodeRef['meta']['drupal_internal__target_id'] ?? null;

    $summary = match ($nodeType) {
      'node--ucb_article' => $nodeAttrs['field_ucb_article_summary'] ?? '',
      default => $nodeAttrs['body']['summary'] ?? '',
    };

    $trustRole = $attributes['trust_role'] ?? '';
    $trustScope = $attributes['trust_scope'] ?? '';
    $allowedRoles = ['primary_source', 'secondary_source', 'subject_matter_contributor', 'unverified'];
    $allowedScopes = ['department_level', 'college_level', 'administrative_unit', 'campus_wide'];

    $topicTerms = [];
    foreach ($relationships['trust_topics']['data'] ?? [] as $topicRef) {
      $topic = $this->findIncludedById($included, $topicRef['type'], $topicRef['id']);
      $remoteName = $topic['attributes']['name'] ?? null;
      if ($remoteName) {
        $matches = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties(['name' => $remoteName, 'vid' => 'trust_topics']);
        if ($localTerm = reset($matches)) {
          $topicTerms[] = $localTerm->id();
          $this->logger->notice('Matched topic "@remote" to local term ID: @id', ['@remote' => $remoteName, '@id' => $localTerm->id()]);
        }
        else {
          $this->logger->warning('No local match found for remote topic: @remote', ['@remote' => $remoteName]);
        }
      }
    }

    $entity->set('title', $title);
    $entity->set('summary', $summary);
    $entity->set('trust_role', in_array($trustRole, $allowedRoles, true) ? $trustRole : '');
    $entity->set('trust_scope', in_array($trustScope, $allowedScopes, true) ? $trustScope : '');
    $entity->set('remote_type', $nodeType);
    $entity->set('source_site', $site);
    $sourceUrl = $relatedNode['links']['self']['href'] ?? '';
    $entity->set('source_url', $this->normalizeUrl($sourceUrl, $internalBase, $publicBase));
    $entity->set('jsonapi_payload', json_encode($item));
    $entity->set('last_fetched', $remoteChanged);
    $entity->set('trust_topics', $topicTerms);
    $entity->set('remote_nid', $remoteNid);

    $focalWide = null;
    $focalSquare = null;

    if (in_array($nodeType, ['node--ucb_article', 'node--ucb_person'], true)) {
      $thumbnailRel = $relatedNode['relationships']['field_ucb_article_thumbnail']['data'] ?? null;
      if (!$thumbnailRel && $nodeType === 'node--ucb_person') {
        $thumbnailRel = $relatedNode['relationships']['field_ucb_person_photo']['data'] ?? null;
      }

      if ($thumbnailRel) {
        $mediaId = $thumbnailRel['id'] ?? null;
        $media = $this->findIncludedById($included, 'media--image', $mediaId);

        if ($media) {
          $fileRel = $media['relationships']['field_media_image']['data'] ?? null;
          $fileId = $fileRel['id'] ?? null;
          $file = $this->findIncludedById($included, 'file--file', $fileId);

          if (!empty($file['links']['focal_image_wide']['href'])) {
            $focalWide = $this->normalizeUrl($file['links']['focal_image_wide']['href'], $internalBase, $publicBase);
          }

          if (!empty($file['links']['focal_image_square']['href'])) {
            $focalSquare = $this->normalizeUrl($file['links']['focal_image_square']['href'], $internalBase, $publicBase);
          }
        }
      }
    }

    $entity->set('focal_image_wide', $focalWide);
    $entity->set('focal_image_square', $focalSquare);

    $entity->save();
    $this->logger->notice('💾 Saved entity @uuid with title: @title', ['@uuid' => $uuid, '@title' => $title]);
  }

  protected function findIncludedById(array $included, string $type, string $id): ?array {
    foreach ($included as $entry) {
      if ($entry['type'] === $type && $entry['id'] === $id) {
        return $entry;
      }
    }
    return null;
  }

  protected function normalizeUrl(string $url, string $internalBase, string $publicBase): string {
    if (str_starts_with($url, $internalBase)) {
      return $publicBase . substr($url, strlen($internalBase));
    }
    return $url;
  }
}
