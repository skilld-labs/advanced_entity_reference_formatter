<?php

namespace Drupal\entity_reference_dynamic_display\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'DynamicDisplay' formatter.
 *
 * @FieldFormatter(
 *   id = "dynamic_display",
 *   label = @Translation("Dynamic Display"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions"
 *   }
 * )
 */
class DynamicDisplay extends EntityReferenceEntityFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'override' => 'none',
      'bundle_modes' => [],
      'delta_modes' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);
    $elements['view_mode']['#title'] = $this->t('Default view mode');
    $target_type = $this->getFieldSetting('target_type');
    $handler_settings = $this->getFieldSetting('handler_settings');
    $target_bundles = $handler_settings['target_bundles'];
    $all_view_modes = $this->entityDisplayRepository->getViewModeOptions($target_type);
    $field_name = $this->fieldDefinition->getName();
    $settings = $this->getSettings();
    $elements['override'] = [
      '#type' => 'radios',
      '#options' => [
        'none' => $this->t('None'),
        'bundle' => $this->t('Select view modes based on target bundle'),
        'delta' => $this->t('Select view modes based on item delta'),
      ],
      '#title' => $this->t('Override options'),
      '#default_value' => $settings['override'],
    ];
    $elements['bundle_modes'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'bundle-view-mode-wrapper'],
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $field_name . '][settings_edit_form][settings][override]"]' => ['value' => 'bundle'],
        ],
      ],
    ];
    foreach ($target_bundles as $bundle) {
      // Get view modes for current bundle only.
      $bundle_view_modes = $this->entityDisplayRepository->getViewModeOptionsByBundle($target_type, $bundle);
      $elements['bundle_modes'][$bundle] = [
        '#type' => 'select',
        '#options' => $bundle_view_modes,
        '#title' => $this->t('View mode for bundle %bundle', ['%bundle' => $bundle]),
        '#default_value' => isset($settings['bundle_modes'][$bundle]) ? $settings['bundle_modes'][$bundle] : NULL,
      ];
    }
    $elements['delta_modes'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'view-mode-delta-wrapper'],
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $field_name . '][settings_edit_form][settings][override]"]' => ['value' => 'delta'],
        ],
      ],
    ];
    $delta_limit = $form_state->get(['delta_limit', $field_name]);
    if (!is_int($delta_limit)) {
      $delta_limit = count($settings['delta_modes']) ?: 0;
      $form_state->set(['delta_limit', $field_name], $delta_limit);
    }
    for ($i = 0; $i < $delta_limit; $i++) {
      $elements['delta_modes'][$i] = [
        '#type' => 'select',
        '#options' => $all_view_modes,
        '#title' => $this->t('View mode for delta %delta', ['%delta' => $i]),
        '#default_value' => isset($settings['delta_modes'][$i]) ? $settings['delta_modes'][$i] : NULL,
      ];
    }
    $form['buttons'] = [
      '#type' => 'container',
    ];
    $elements['buttons']['add_more'] = [
      '#type' => 'submit',
      '#name' => 'add_more',
      '#value' => $this->t('Add delta'),
      '#submit' => [[get_class($this), 'addMoreDeltaSubmit']],
      '#processed' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $field_name . '][settings_edit_form][settings][override]"]' => ['value' => 'delta'],
        ],
      ],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxDeltaSubmit'],
        'wrapper' => 'view-mode-delta-wrapper',
      ],
      '#attributes' => [
        'data-field-name' => $field_name,
      ],
    ];
    $elements['buttons']['remove'] = [
      '#processed' => FALSE,
      '#type' => 'submit',
      '#name' => 'remove',
      '#value' => $this->t('Remove last'),
      '#submit' => [[get_class($this), 'removeDeltaSubmit']],
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $field_name . '][settings_edit_form][settings][override]"]' => ['value' => 'delta'],
        ],
      ],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxDeltaSubmit'],
        'wrapper' => 'view-mode-delta-wrapper',
      ],
      '#attributes' => [
        'data-field-name' => $field_name,
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
  public static function addMoreDeltaSubmit(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $field_name = $triggering_element['#attributes']['data-field-name'];
    $delta_limit = $form_state->get(['delta_limit', $field_name]);
    $form_state->set(['delta_limit', $field_name], $delta_limit + 1);
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
  public static function removeDeltaSubmit(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $field_name = $triggering_element['#attributes']['data-field-name'];
    $delta_limit = $form_state->get(['delta_limit', $field_name]);
    if ($delta_limit >= 1) {
      $form_state->set(['delta_limit', $field_name], $delta_limit - 1);
      $form_state->setRebuild();
    }
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
  public static function ajaxDeltaSubmit(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    // Button located on another level inside form, so we are traveling up.
    array_splice($triggering_element['#array_parents'], -2);
    $triggering_element['#array_parents'][] = 'delta_modes';
    $element = NestedArray::getValue($form, $triggering_element['#array_parents']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $view_modes = $this->entityDisplayRepository->getViewModeOptions($this->getFieldSetting('target_type'));

    $override = $this->getSetting('override');
    if ($override == 'bundle') {
      $bundle_modes = $this->getSetting('bundle_modes') ?: [];
      foreach ($bundle_modes as $bundle => $view_mode) {
        $summary[] = t('Bundle #@bundle Rendered as @mode', [
          '@bundle' => $bundle,
          '@mode' => isset($view_modes[$view_mode]) ? $view_modes[$view_mode] : $view_mode,
        ]);
      }
    }
    elseif ($override == 'delta') {
      $delta_modes = $this->getSetting('delta_modes') ?: [];
      foreach ($delta_modes as $delta => $view_mode) {
        $summary[] = t('Delta #@delta Rendered as @mode', [
          '@delta' => $delta,
          '@mode' => isset($view_modes[$view_mode]) ? $view_modes[$view_mode] : $view_mode,
        ]);
      }
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // We duplicate code of parent method here to avoid duplicate render
    // of entity against replacing item in result array.
    $override = $this->getSetting('override');
    $bundle_modes = $this->getSetting('bundle_modes') ?: [];
    $delta_modes = $this->getSetting('delta_modes') ?: [];
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      // Default view mode.
      $view_mode = $this->getSetting('view_mode');

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
      // Use specific view mode if needed.
      if ($override == 'bundle' && isset($bundle_modes[$entity->bundle()])) {
        $view_mode = $bundle_modes[$entity->bundle()];
      }
      elseif ($override == 'delta' && isset($delta_modes[$delta])) {
        $view_mode = $delta_modes[$delta];
      }
      $elements[$delta] = $view_builder->view($entity, $view_mode, $entity->language()->getId());

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
