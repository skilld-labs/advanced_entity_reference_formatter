<?php

namespace Drupal\entity_reference_display_switcher\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'ParagraphsViewModeSwitcherFormatter' formatter.
 *
 * @FieldFormatter(
 *   id = "entity_reference_display_switcher",
 *   label = @Translation("Entity reference display switcher"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions"
 *   }
 * )
 */
class EntityReferenceDisplaySwitcherFormatter extends EntityReferenceEntityFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'view_mode' => 'default',
      'view_mode_delta' => [],
      'link' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $elements['view_mode']['#title'] = $this->t('Default view mode');
    $elements['view_mode_delta'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'view-mode-delta-wrapper'],
    ];
    $view_modes = $this->entityDisplayRepository->getViewModeOptions($this->getFieldSetting('target_type'));
    $setting = $this->getSettings();
    $items = $form_state->get('delta_items');
    if (is_null($items)) {
      $items = count($this->getSetting('view_mode_delta')) ?: 0;
      $form_state->set('delta_items', $items);
    }
    for ($i = 0; $i < $items; $i++) {
      $elements['view_mode_delta'][$i] = [
        '#type' => 'select',
        '#options' => $view_modes,
        '#title' => $this->t('View mode for delta %delta', ['%delta' => $i]),
        '#default_value' => $setting["view_mode_delta"][$i],
      ];
    }

    $form['buttons']['actions'] = [
      '#type' => 'container',
    ];
    $elements['buttons']['add_more'] = [
      '#type' => 'submit',
      '#name' => 'add_more',
      '#value' => $this->t('Add delta'),
      '#submit' => [[get_class($this), 'addMoreSubmit']],
      '#processed' => FALSE,
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxSubmit'],
        'wrapper' => 'view-mode-delta-wrapper',
      ],
    ];
    $elements['buttons']['remove'] = [
      '#processed' => FALSE,
      '#type' => 'submit',
      '#name' => 'add_more',
      '#value' => $this->t('Remove last'),
      '#submit' => [[get_class($this), 'removeSubmit']],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxSubmit'],
        'wrapper' => 'view-mode-delta-wrapper',
      ],
    ];

    return $elements;
  }

  /**
   * Add more submit callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $items = $form_state->get('delta_items');
    $form_state->set('delta_items', $items + 1);
    $form_state->setRebuild();
  }

  /**
   * Remove item submit callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function removeSubmit(array $form, FormStateInterface $form_state) {
    $items = $form_state->get('delta_items');
    $form_state->set('delta_items', $items - 1);
    $form_state->setRebuild();
  }

  /**
   * Ajax submit callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Items form element.
   */
  public static function ajaxSubmit(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    // Button located on another level inside form, so we are traveling up.
    array_splice($triggering_element['#array_parents'], -2);
    $triggering_element['#array_parents'][] = 'view_mode_delta';
    $element = NestedArray::getValue($form, $triggering_element['#array_parents']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $view_mode_delta = $this->getSetting('view_mode_delta') ?: [];
    $view_modes = $this->entityDisplayRepository->getViewModeOptions($this->getFieldSetting('target_type'));
    foreach ($view_mode_delta as $delta => $view_mode) {
      $summary[] = t('Delta #@delta Rendered as @mode', [
        '@delta' => substr($delta, 10),
        '@mode' => isset($view_modes[$view_mode_delta[$delta]]) ? $view_modes[$view_mode_delta[$delta]] : $view_mode_delta[$view_mode_delta[$delta]],
      ]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // We duplicate code of parent method here to avoid duplicate render
    // of entity against replacing item in result array.
    $view_mode = $this->getSetting('view_mode');
    $view_mode_delta = $this->getSetting('view_mode_delta') ?: [];
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      $recursive_render_id = $items->getFieldDefinition()
        ->getTargetEntityTypeId()
        . $items->getFieldDefinition()->getTargetBundle()
        . $items->getName()
        . $items->getEntity()->id()
        . $entity->getEntityTypeId()
        . $entity->id();

      if (isset(static::$recursiveRenderDepth[$recursive_render_id])) {
        static::$recursiveRenderDepth[$recursive_render_id]++;
      }
      else {
        static::$recursiveRenderDepth[$recursive_render_id] = 1;
      }

      // Protect ourselves from recursive rendering.
      if (static::$recursiveRenderDepth[$recursive_render_id] > static::RECURSIVE_RENDER_LIMIT) {
        $this->loggerFactory->get('entity')
          ->error('Recursive rendering detected when rendering entity %entity_type: %entity_id, using the %field_name field on the %bundle_name bundle. Aborting rendering.', [
            '%entity_type' => $entity->getEntityTypeId(),
            '%entity_id' => $entity->id(),
            '%field_name' => $items->getName(),
            '%bundle_name' => $items->getFieldDefinition()->getTargetBundle(),
          ]);
        return $elements;
      }
      $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
      // Switch view mode if needed.
      if (isset($view_mode_delta[$delta])) {
        $elements[$delta] = $view_builder->view($entity, $view_mode_delta[$delta], $entity->language()
          ->getId());
      }
      else {
        $elements[$delta] = $view_builder->view($entity, $view_mode, $entity->language()
          ->getId());
      }

      if (!empty($items[$delta]->_attributes) && !$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
        $items[$delta]->_attributes += [
          'resource' => $entity->toUrl()
            ->toString(),
        ];
      }
    }
    return $elements;
  }

}
