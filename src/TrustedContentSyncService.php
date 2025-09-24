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

    $config = $this->configFactory->get('ucb_trusted_content_discovery.sites');
    $sites = $config->get('sites') ?? [];

    // no sites in config
    if (empty($sites)) {
      return;
    }

    $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

    // iterate over config, process sites
    foreach ($sites as $site_name => $site_info) {

      $publicBase = rtrim($site_info['public'] ?? '', '/');
      $internalBase = rtrim($site_info['internal'] ?? '', '/');
      $base_url = $publicBase;
      // internal
      if ($isDdev && !empty($internalBase)) {
        $base_url = $internalBase;
        $this->logger->notice('Using internal URL for DDEV: @internal', ['@internal' => $internalBase]);
      }
      // no valid url
      if (empty($base_url)) {
        $this->logger->error('No valid URL defined for site: @site', ['@site' => $site_name]);
        continue;
      }
    // Query for all node types
    $query = http_build_query([
      'include' => 'trust_topics,node_id,node_id.field_ucb_article_thumbnail,node_id.field_ucb_article_thumbnail.field_media_image,node_id.field_ucb_person_photo,node_id.field_ucb_person_photo.field_media_image,node_id.field_social_sharing_image,node_id.field_social_sharing_image.field_media_image',
      'fields[trust_metadata--trust_metadata]' => 'trust_role,trust_scope,timeliness,audience,trust_contact,trust_topics,node_id,trust_syndication_enabled,syndication_consumer_sites,syndication_total_views,syndication_consumer_sites_list,site_affiliation,content_authority',
      'fields[taxonomy_term--trust_topics]' => 'name',
      'fields[node--basic_page]' => 'title,body,changed,nid,path,field_social_sharing_image',
      'fields[node--ucb_person]' => 'title,body,changed,field_ucb_person_photo,nid,path',
      'fields[node--ucb_article]' => 'title,field_ucb_article_summary,field_ucb_article_thumbnail,changed,nid,path',
      'fields[media--image]' => 'field_media_image',
      'fields[file--file]' => 'uri,url, alt',
      'sort' => '-node_id.changed',
      'filter[trust_syndication_enabled][value]' => '1'
    ]);

      $url = rtrim($base_url, '/') . '/jsonapi/trust_metadata/trust_metadata?' . $query;

      try {
        $json = $this->fetchAllPaginated($url, $publicBase, $isDdev);

        if (!is_array($json) || !isset($json['data'])) {
          $this->logger->error('Invalid or missing "data" key in JSON response from @url', ['@url' => $url]);
          continue;
        }

        // store list of sites to delete stale
        $seenRemoteUuids = [];

        foreach ($json['data'] ?? [] as $item) {
          $remote_uuid = $this->generateRemoteUuid($publicBase, $item['id'] ?? '');
          if (empty($remote_uuid)) {
            $this->logger->warning('Skipping item with empty remote UUID seed');
            continue;
          }
          // add uuid to collection save
          $seenRemoteUuids[] = $remote_uuid;
          $this->saveEntity($item, $json['included'] ?? [], $site_name, $internalBase, $publicBase);
        }
        // run delete
        $this->removeStaleEntities($publicBase, $seenRemoteUuids);

      }
      catch (\Exception $e) {
        $this->logger->error('Error: @msg', ['@msg' => $e->getMessage()]);
      }
    }
  }

  protected function saveEntity(array $item, array $included, string $site, string $internalBase, string $publicBase): void {
    // skip item with missing attributes
    if (empty($item['id']) || empty($item['attributes'])) {
      return;
    }

    $remote_uuid = $this->generateRemoteUuid($publicBase, $item['id'] ?? '');
    if (empty($remote_uuid)) {
      $this->logger->warning('Skipping item with empty remote UUID seed');
      return;
    }
    $attributes = $item['attributes'];
    $relationships = $item['relationships'] ?? [];
    
    // Debug: Log the raw attributes to see what we're getting
    $this->logger->info('Raw attributes for @uuid: @attrs', [
      '@uuid' => $item['id'] ?? 'unknown',
      '@attrs' => json_encode($attributes),
    ]);

    $nodeRef = $relationships['node_id']['data'] ?? null;
    $nodeId = $nodeRef['id'] ?? null;
    $nodeType = $nodeRef['type'] ?? null;

    // related node not found
    $relatedNode = $this->findIncludedById($included, $nodeType, $nodeId);
    if (!$relatedNode || empty($relatedNode['attributes'])) {
      return;
    }

    $nodeAttrs = $relatedNode['attributes'];
    $remoteChanged = strtotime($nodeAttrs['changed'] ?? '0');

    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');
    $entities = $storage->loadByProperties([
      'remote_uuid' => $remote_uuid,
      'source_site' => $publicBase,
    ]);
    $entity = reset($entities);

    $is_update = FALSE;

    if ($entity) {
      // Check if entity was previously unpublished and restore it
      $was_unpublished = FALSE;
      if (!$entity->get('is_published')->value) {
        $entity->set('is_published', TRUE);
        $was_unpublished = TRUE;
        $this->logger->info('Restoring previously unpublished entity: @uuid', ['@uuid' => $remote_uuid]);
      }
      
      $localLastFetched = (int) $entity->get('last_fetched')->value;
      
      // If entity was unpublished, always update it regardless of timestamp
      // If entity wasn't unpublished, only update if remote content is newer
      if (!$was_unpublished && $remoteChanged <= $localLastFetched) {
        // log telemetry inline
        $telemetry_storage = $this->entityTypeManager->getStorage('ucb_trusted_content_telemetry');
        $telemetry = $telemetry_storage->create([
          'reference_uuid' => $remote_uuid,
          'trusted_reference' => $entity->id(),
          'fetched' => \Drupal::time()->getCurrentTime(),
          'consumer_site_count' => $attributes['syndication_consumer_sites'] ?? 0,
          'consumer_site_list' => isset($attributes['syndication_consumer_sites_list']) ? json_encode($attributes['syndication_consumer_sites_list']) : '',
          'total_views' => $attributes['syndication_total_views'] ?? 0,
        ]);
        $telemetry->save();
        return;
      }

      $is_update = TRUE;
    }
    else {
      $entity = $storage->create([
        'remote_uuid' => $remote_uuid,
        'source_site' => $publicBase,
      ]);
    }


    // create
    $title = $nodeAttrs['title'] ?? 'Untitled';
    $remoteNid = $nodeRef['meta']['drupal_internal__target_id'] ?? null;

    $summary = match ($nodeType) {
      'node--ucb_article' => $nodeAttrs['field_ucb_article_summary'] ?? '',
      default => $nodeAttrs['body']['summary'] ?? '',
    };

    $trustRole = $attributes['trust_role'] ?? '';
    $trustScope = $attributes['trust_scope'] ?? '';
    $timeliness = $attributes['timeliness'] ?? '';
    $audience = $attributes['audience'] ?? '';
    $allowedRoles = ['primary_source', 'secondary_source', 'subject_matter_contributor', 'unverified'];
    $allowedScopes = ['department_level', 'college_level', 'administrative_unit', 'campus_wide'];
    $allowedTimeliness = ['evergreen', 'fall_semester', 'spring_semester', 'summer_semester', 'winter_semester'];
    $allowedAudience = ['students', 'faculty', 'staff', 'alumni'];

    $topicTerms = [];
    foreach ($relationships['trust_topics']['data'] ?? [] as $topicRef) {
      $topic = $this->findIncludedById($included, $topicRef['type'], $topicRef['id']);
      $remoteName = $topic['attributes']['name'] ?? null;
      if ($remoteName) {
        $matches = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties(['name' => $remoteName, 'vid' => 'trust_topics']);
        // match topics
        if ($localTerm = reset($matches)) {
          $topicTerms[] = $localTerm->id();
        }
        else {
          $this->logger->warning('No local match found for remote topic: @remote', ['@remote' => $remoteName]);
        }
      }
    }

    // Get the node url
    $remotePath = $nodeAttrs['path']['alias'] ?? '/node/' . ($nodeAttrs['nid'] ?? $remoteNid ?? 'UNKNOWN');

    // Save all the fields to the entity on create or update
    $entity->set('title', $title);
    $entity->set('summary', $summary);
    $entity->set('trust_role', in_array($trustRole, $allowedRoles, true) ? $trustRole : '');
    $entity->set('trust_scope', in_array($trustScope, $allowedScopes, true) ? $trustScope : '');
    if ($timeliness !== '') {
      $entity->set('timeliness', in_array($timeliness, $allowedTimeliness, true) ? $timeliness : '');
    }
    if ($audience !== '') {
      $entity->set('audience', in_array($audience, $allowedAudience, true) ? $audience : '');
    }
    // Affiliation
    if (!empty($attributes['site_affiliation'])) {
      $entity->set('site_affiliation', (string) $attributes['site_affiliation']);
    }
    if (array_key_exists('content_authority', $attributes)) {
      $entity->set('content_authority', (string) ($attributes['content_authority'] ?? ''));
    }
    $entity->set('remote_type', $nodeType);
    // Store the public - facing link for reference later -- link to article and use in web component.
    $entity->set('source_site', $publicBase);
    $sourceUrl = $relatedNode['links']['self']['href'] ?? '';
    $entity->set('source_url', $this->normalizeUrl($sourceUrl, $internalBase, $publicBase));
    $entity->set('remote_path', $remotePath);
    $entity->set('jsonapi_payload', json_encode($item));
    $entity->set('last_fetched', $remoteChanged);
    $entity->set('trust_topics', $topicTerms);
    $entity->set('remote_nid', $remoteNid);

    $focalWide = null;
    $focalSquare = null;
    $altText = null;

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
          $altText = $fileRel['meta']['alt'] ?? null;

          if (!empty($file['links']['focal_image_wide']['href'])) {
            $focalWide = html_entity_decode($file['links']['focal_image_wide']['href']);
          }

          if (!empty($file['links']['focal_image_square']['href'])) {
            $focalSquare = html_entity_decode($file['links']['focal_image_square']['href']);
          }
        }
      }
    }
    elseif ($nodeType === 'node--basic_page') {
      $thumbnailRel = $relatedNode['relationships']['field_social_sharing_image']['data'] ?? null;

      if ($thumbnailRel) {
        $mediaId = $thumbnailRel['id'] ?? null;
        $media = $this->findIncludedById($included, 'media--image', $mediaId);

        if ($media) {
          $fileRel = $media['relationships']['field_media_image']['data'] ?? null;
          $fileId = $fileRel['id'] ?? null;
          $file = $this->findIncludedById($included, 'file--file', $fileId);
          $altText = $fileRel['meta']['alt'] ?? null;

          if (!empty($file['links']['focal_image_wide']['href'])) {
            $focalWide = html_entity_decode($file['links']['focal_image_wide']['href']);
          }

          if (!empty($file['links']['focal_image_square']['href'])) {
            $focalSquare = html_entity_decode($file['links']['focal_image_square']['href']);
          }
        }
      }
    }

    $entity->set('focal_image_wide', $focalWide);
    $entity->set('focal_image_square', $focalSquare);
    $entity->set('focal_image_alt', $altText);

    // Set syndication data from remote trust metadata
    $consumerSites = $attributes['syndication_consumer_sites'] ?? 0;
    $totalViews = $attributes['syndication_total_views'] ?? 0;
    
    $this->logger->info('Setting syndication data for @uuid: consumer_sites=@sites, total_views=@views', [
      '@uuid' => $remote_uuid,
      '@sites' => $consumerSites,
      '@views' => $totalViews,
    ]);
    
    $entity->set('syndication_consumer_sites', $consumerSites);
    $entity->set('syndication_total_views', $totalViews);

    $entity->save();

    // after saving the content reference, log telemetry
    $telemetry_storage = $this->entityTypeManager->getStorage('ucb_trusted_content_telemetry');
    $telemetry = $telemetry_storage->create([
      'reference_uuid' => $remote_uuid,
      'trusted_reference' => $entity->id(),
      'fetched' => \Drupal::time()->getCurrentTime(),
      'consumer_site_count' => $consumerSites,
      'consumer_site_list' => isset($attributes['syndication_consumer_sites_list']) ? json_encode($attributes['syndication_consumer_sites_list']) : '',
      'total_views' => $attributes['syndication_total_views'] ?? 0,
    ]);
    $telemetry->save();
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

  protected function generateRemoteUuid(string $site, string $remoteId): string {
    if (empty($remoteId)) {
      return '';
    }
    return md5($site . ':' . $remoteId);
  }

  protected function removeStaleEntities(string $sourceSite, array $seenRemoteUuids): void {
    $storage = $this->entityTypeManager->getStorage('ucb_trusted_content_reference');

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('source_site', $sourceSite);

    if (!empty($seenRemoteUuids)) {
      $query->condition('remote_uuid', $seenRemoteUuids, 'NOT IN');
    }

    $ids = $query->execute();

    if (!empty($ids)) {
      $entities = $storage->loadMultiple($ids);
      
      // Mark entities as unpublished instead of deleting them
      foreach ($entities as $entity) {
        $entity->set('is_published', FALSE);
        $entity->save();
      }
      
      $this->logger->notice('Marked @count entries as unpublished from @site', [
        '@count' => count($ids),
        '@site' => $sourceSite,
      ]);
    }
  }

  protected function fetchAllPaginated(string $url, string $publicBase, bool $isDdev, array $accumulated = []): array {
  try {
    $this->logger->info("Fetching page: $url");

    $response = $this->httpClient->get($url, [
      'headers' => [
        'Accept' => 'application/json',
        'Host' => parse_url($publicBase, PHP_URL_HOST),
      ],
      'verify' => !$isDdev,
    ]);

    $json = json_decode((string) $response->getBody(), true);

    if (!is_array($json) || !isset($json['data'])) {
      $this->logger->error('Invalid JSON response from: @url', ['@url' => $url]);
      return $accumulated;
    }

    $accumulated['data'] = array_merge($accumulated['data'] ?? [], $json['data']);
    if (isset($json['included'])) {
      $accumulated['included'] = array_merge($accumulated['included'] ?? [], $json['included']);
    }

    if (!empty($json['links']['next']['href'])) {
      $nextUrl = $json['links']['next']['href'];
      $this->logger->info("Next page detected: $nextUrl");
      return $this->fetchAllPaginated($nextUrl, $publicBase, $isDdev, $accumulated);
    }

    $this->logger->info("No more pages. Total items: " . count($accumulated['data'] ?? []));
    return $accumulated;

  } catch (\Exception $e) {
    $this->logger->error('Pagination fetch failed: @msg', ['@msg' => $e->getMessage()]);
    return $accumulated;
  }
}



}
