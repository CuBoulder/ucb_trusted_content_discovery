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

    $fields['remote_uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('Remote UUID'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['remote_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Remote Content Type'))
      ->setRequired(TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE);

    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Summary'));

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

    $fields['source_site'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Site'));

    $fields['source_url'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Source URL'));

    $fields['jsonapi_payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('JSON:API Payload'));

    $fields['last_fetched'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last Fetched'));

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
      ->setRequired(FALSE);

    $fields['focal_image_wide'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Focal Image Wide'))
      ->setDescription(t('URL for the wide image style.'));

    $fields['focal_image_square'] = BaseFieldDefinition::create('uri')
      ->setLabel(t('Focal Image Square'))
      ->setDescription(t('URL for the square image style.'));

    return $fields;
  }

}
