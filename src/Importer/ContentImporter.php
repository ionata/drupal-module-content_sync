<?php

namespace Drupal\content_sync\Importer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\serialization\Normalizer\SerializedColumnNormalizerTrait;
use Symfony\Component\Serializer\Serializer;

class ContentImporter implements ContentImporterInterface {

  use SerializedColumnNormalizerTrait;

  protected $format = 'yaml';

  protected $updateEntities = TRUE;

  protected $context = [];

  /**
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * ContentImporter constructor.
   */
  public function __construct(Serializer $serializer, EntityTypeManagerInterface $entity_type_manager) {
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
  }

  public function importEntity($decoded_entity, $context = []) {
    $context = $this->context + $context;
    if (!empty($context['entity_type'])) {
      $entity_type_id = $context['entity_type'];
    }
    elseif (!empty($decoded_entity['_content_sync']['entity_type'])) {
      $entity_type_id = $decoded_entity['_content_sync']['entity_type'];
    }
    else {
      return NULL;
    }

    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);

    //Exception for parent null -- allowing the term to be displayed on the taxonomy list.
    if ($entity_type_id == 'taxonomy_term') {
      if(empty($decoded_entity['parent'])){
        $decoded_entity['parent']['target_id'] = 0;
      }
    }

    //Get Translations before denormalize
    if(!empty($decoded_entity['_translations'])){
      $entity_translations = $decoded_entity['_translations'];
    }

    $entity = $this->serializer->denormalize($decoded_entity, $entity_type->getClass(), $this->format, $context);

    if (!empty($entity)) {
      // Prevent Anonymous User from being saved.
      if ($entity_type_id == 'user' && !$entity->isNew() && (int) $entity->id() === 0) {
        return $entity;
      }
      $entity = $this->syncEntity($entity);
    }

    // Include Translations
    if ($entity){
      if ( isset($entity_translations) && is_array($entity_translations) ) {
        $this->updateTranslation($entity, $entity_type, $entity_translations, $context);
      }
    }
    return $entity;
  }

  /**
   * Updates translations.
   *
   * @param $entity
   *   An entity object.
   * @param \Drupal\Core\Entity\ContentEntityType $entity_type
   *   A ContentEntityType object.
   * @param array $entity_translations
   *   An array of translations.
   * @param $context
   *   The batch context.
   */
  protected function updateTranslation(&$entity, $entity_type, $entity_translations, $context) {
    foreach ($entity_translations as $langcode => $translation) {
      // Denormalize.
      $translation = $this->serializer->denormalize($translation, $entity_type->getClass(), $this->format, $context);

      // If an entity has a translation - update one, otherwise - add a new one.
      $entity_translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity->addTranslation($langcode);

      // Get fields definitions.
      $fields = $translation->getFieldDefinitions();

      foreach ($translation as $itemID => $item) {
        if ($entity_translation->hasField($itemID)){
          if ($fields[$itemID]->isTranslatable() == TRUE){
            $entity_translation->$itemID->setValue($item->getValue());
          }
        }
      }

      // Avoid issues updating revisions.
      if ($entity_translation->getEntityType()->hasKey('revision')) {
        $entity_translation->updateLoadedRevisionId();
        $entity_translation->setNewRevision(FALSE);
      }

      // Save the entity translation.
      $entity_translation->save();
    }

  }

  /**
   * @return string
   */
  public function getFormat() {
    return $this->format;
  }

  /**
   * Synchronize a given entity.
   *
   * @param ContentEntityInterface $entity
   *   The entity to update.
   *
   * @return ContentEntityInterface
   *   The updated entity
   */
  protected function syncEntity(ContentEntityInterface $entity) {
    $preparedEntity = $this->prepareEntity($entity);
    if ($this->validateEntity($preparedEntity)) {
      $preparedEntity->save();
      return $preparedEntity;
    }
    elseif (!$preparedEntity->isNew()) {
      return $preparedEntity;
    }
    return NULL;
  }

  /**
   * Serializes fields which have to be stored serialized.
   *
   * @param $entity
   *   The entity to update.
   *
   * @return mixed
   *   The entity with the fields being serialized.
   */
  protected function processSerializedFields($entity) {
    foreach ($entity->getTypedData() as $name => $field_items) {
      foreach ($field_items as $field_item) {
        // Determine whether field has serialized properties,
        // and if so, sanitize.
        $serialized_property_names = $this->getCustomSerializedPropertyNames($field_item);
        if (!empty($serialized_property_names)) {
          $field_values = $field_item->getValue();
          foreach ($serialized_property_names as $property_name) {
            if(isset($field_values[$property_name])){
              $field_values[$property_name] = (is_array($field_values[$property_name])) ? serialize($field_values[$property_name]) : $field_values[$property_name];
            }
          }
          $entity->set($name, $field_values);
        }
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareEntity(ContentEntityInterface $entity) {
    $uuid = $entity->uuid();
    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
                                               ->loadByProperties(['uuid' => $uuid]);

    if (!empty($original_entity)) {
      $original_entity = reset($original_entity);
      if (!$this->updateEntities) {
        return $original_entity;
      }

      // Overwrite the received properties.
      if (!empty($entity->_restSubmittedFields)) {
        foreach ($entity->_restSubmittedFields as $field_name) {
          if ($this->isValidEntityField($original_entity, $entity, $field_name)) {
            $original_entity->set($field_name, $entity->get($field_name)
                                                      ->getValue());
          }
        }
      }
      return $this->processSerializedFields($original_entity);
    }
    $duplicate = $entity->createDuplicate();
    $entity_type = $entity->getEntityType();
    $duplicate->{$entity_type->getKey('uuid')}->value = $uuid;

    return $this->processSerializedFields($duplicate);
  }

  /**
   * Checks if the entity field needs to be synchronized.
   *
   * @param ContentEntityInterface $original_entity
   *   The original entity.
   * @param ContentEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   True if the field needs to be synced.
   */
  protected function isValidEntityField(ContentEntityInterface $original_entity, ContentEntityInterface $entity, $field_name) {
    $valid = TRUE;
    $entity_keys = $entity->getEntityType()->getKeys();
    // Check if the target entity has the field.
    if (!$entity->hasField($field_name)) {
      $valid = FALSE;
    }
    // Entity key fields need special treatment: together they uniquely
    // identify the entity. Therefore it does not make sense to modify any of
    // them. However, rather than throwing an error, we just ignore them as
    // long as their specified values match their current values.
    elseif (in_array($field_name, $entity_keys, TRUE)) {
      // Unchanged values for entity keys don't need access checking.
      if ($original_entity->get($field_name)
                          ->getValue() === $entity->get($field_name)->getValue()
          // It is not possible to set the language to NULL as it is
          // automatically re-initialized.
          // As it must not be empty, skip it if it is.
          || isset($entity_keys['langcode'])
          && $field_name === $entity_keys['langcode']
          && $entity->get($field_name)->isEmpty()
          || $field_name === $entity->getEntityType()->getKey('id')
          || $entity->getEntityType()->isRevisionable()
          && $field_name === $entity->getEntityType()->getKey('revision')
      ) {
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEntity(ContentEntityInterface $entity) {
    $reflection = new \ReflectionClass($entity);
    $valid = TRUE;
    if ($reflection->implementsInterface('\Drupal\user\UserInterface')) {
      $validations = $entity->validate();
      if (count($validations)) {
        /**
         * @var ConstraintViolation $validation
         */
        foreach ($validations as $validation) {
          if (!empty($this->getContext()['skipped_constraints']) && in_array(get_class($validation->getConstraint()), $this->getContext()['skipped_constraints'])) {
            continue;
          }
          $valid = FALSE;
          \Drupal::logger('content_sync')
                 ->error($validation->getMessage());
        }
      }
    }
    return $valid;
  }

  /**
   * @return array
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * @param array $context
   */
  public function setContext($context) {
    $this->context = $context;
  }

}
