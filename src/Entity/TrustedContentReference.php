<?php

namespace Drupal\ucb_trusted_content_discovery\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * @ContentEntityType(
 *   id = "ucb_trusted_content_reference",
 *   label = @Translation("Trusted Content Reference"),
 *   base_table = "ucb_trusted_content_reference",
 *   data_table = "ucb_trusted_content_reference_field_data",
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "title",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid",
 *   },
 *   admin_permission = "administer trusted content references"
 * )
 */
class TrustedContentReference extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('auto_increment', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['remote_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Remote UUID'))
      ->setDescription(t('A deterministic UUID generated from site + remote resource ID.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['remote_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Remote Content Type'))
      ->setRequired(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE);

    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Summary'));

    $fields['last_updated'] = BaseFieldDefinition::create('timestamp')
    ->setLabel(t('Last Updated on Remote'))
    ->setDescription(t('Timestamp from the remote node\'s "changed" field.'));

    $fields['trust_role'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Trust Role'))
      ->setDescription(t('The trust role of the content.'))
      ->setSettings([
        'allowed_values' => [
          'primary_source' => 'Primary Source',
          'secondary_source' => 'Secondary Source',
          'subject_matter_contributor' => 'Subject Matter Contributor/Expert',
          'unverified' => 'Unverified',
        ],
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['trust_scope'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Trust Scope'))
      ->setSettings([
        'allowed_values' => [
          'department_level' => 'Department Level',
          'college_level' => 'College Level',
          'administrative_unit' => 'Administrative Unit',
          'campus_wide' => 'Campus-wide',
        ],
      ])

      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['timeliness'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Timeliness'))
      ->setDescription(t('The timeliness of the content (evergreen, semester-specific, etc.).'))
      ->setSettings([
        'allowed_values' => [
          'evergreen' => 'Evergreen',
          'fall_semester' => 'Fall Semester',
          'spring_semester' => 'Spring Semester',
          'summer_semester' => 'Summer Semester',
          'winter_semester' => 'Winter Semester',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 2.5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2.5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['audience'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Audience'))
      ->setDescription(t('The target audience for the content.'))
      ->setSettings([
        'allowed_values' => [
          'students' => 'Students',
          'faculty' => 'Faculty',
          'staff' => 'Staff',
          'alumni' => 'Alumni',
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 2.6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2.6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['source_site'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Site'));

    $fields['source_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Source URL'));

    $fields['jsonapi_payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('JSON:API Payload'));

    $fields['last_fetched'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last Fetched'));

    $fields['remote_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Remote Path'))
      ->setDescription(t('The URL path or alias of the remote node (e.g. /2025/06/my-article).'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE);

    $fields['trust_topics'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Trust Topics'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default')
      ->setSetting('handler_settings', [
        'target_bundles' => ['trust_topics'],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete_tags',
        'weight' => 6,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE)
      ->setRequired(FALSE);

    $fields['focal_image_wide'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Focal Image Wide'))
      ->setDescription(t('URL for the wide image style.'));

    $fields['focal_image_square'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Focal Image Square'))
      ->setDescription(t('URL for the square image style.'));

    $fields['remote_nid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Remote Node ID'))
      ->setDescription(t('The internal Drupal node ID of the referenced content.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('filter', TRUE);

    $fields['focal_image_alt'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Focal Image Alt Text'))
      ->setDescription(t('Alt text for the focal image.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 7,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 7,
      ])
      ->setDisplayConfigurable('form', TRUE);

    // Telemetry fields from ucb_trust_schema
    $fields['syndication_consumer_sites'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Consumer Sites Count'))
      ->setDescription(t('Number of sites that are consuming this content.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 8,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['syndication_total_views'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Total Views'))
      ->setDescription(t('Total number of views across all consumer sites.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 9,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 9,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Publication status for soft deletion
    $fields['is_published'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is Published'))
      ->setDescription(t('Whether this content is currently published and available.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 10,
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
