<?php

namespace Drupal\ucb_trusted_content_discovery\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Trusted Content Telemetry entity.
 *
 * @ContentEntityType(
 *   id = "ucb_trusted_content_telemetry",
 *   label = @Translation("Trusted Content Telemetry"),
 *   base_table = "ucb_trusted_content_telemetry",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "reference_uuid"
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   admin_permission = "administer trusted content telemetry"
 * )
 */
class TrustedContentTelemetry extends ContentEntityBase {

  public static function getCurrentTimestamp() {
    return [\Drupal::time()->getCurrentTime()];
  }


  /**
   * Defines base fields for the telemetry entity.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Internal ID.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE);

    // UUID of this telemetry entry.
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setReadOnly(TRUE);

    // The UUID of the related ucb_trusted_content_reference.
    $fields['reference_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Reference UUID'))
      ->setDescription(t('UUID of the related Trusted Content Reference entity.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Actual entity reference.
    $fields['trusted_reference'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Trusted Reference Entity'))
      ->setDescription(t('Entity reference to the source Trusted Content Reference.'))
      ->setSetting('target_type', 'ucb_trusted_content_reference')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -4,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Timestamp of this telemetry record.
    $fields['fetched'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Logged At'))
      ->setDescription(t('When this telemetry record was created.'))
      ->setDefaultValueCallback('Drupal\\ucb_trusted_content_discovery\\Entity\\TrustedContentTelemetry::getCurrentTimestamp')
      ->setDisplayConfigurable('view', TRUE);

    // Syndication consumer site count.
    $fields['consumer_site_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Consumer Site Count'))
      ->setDescription(t('Number of consumer sites syndicating this content.'))
      ->setDisplayConfigurable('view', TRUE);

    // List of consumer sites.
    $fields['consumer_site_list'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Consumer Site List'))
      ->setDescription(t('List of consumer site identifiers (JSON or delimited).'))
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Total syndicated views.
    $fields['total_views'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total Views'))
      ->setDescription(t('The total number of syndicated views reported.'))
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}

