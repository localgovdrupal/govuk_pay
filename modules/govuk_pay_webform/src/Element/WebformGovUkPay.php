<?php

namespace Drupal\govuk_pay_webform\Element;

use Drupal\webform\Element\WebformHtmlEditor;
use Drupal\Core\Render\Element\FormElementBase;

/**
 * Provides a render element for webform GOV.UK Pay.
 *
 * @FormElement("webform_govuk_pay")
 */
class WebformGovUkPay extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#pre_render' => [
        [$class, 'preRenderWebformGovUkPay'],
      ],
    ];
  }

  /**
   * Finishes generatign the Webform element render array.
   *
   * @param array $element
   *   Initial element render array.
   *
   * @return array
   *   Final element render array.
   */
  public static function preRenderWebformGovUkPay(array $element) {
    if ($element['#default_content'] ?? FALSE) {
      // Replace #content with renderable webform HTML editor markup.
      $element['markup'] = WebformHtmlEditor::checkMarkup("
        <div id='-govuk-pay--default-content'>
          <div class='content'>
            Once you submit this form, you will be redirected to GOV.UK Pay to complete payment.<br/>
            The following card types are accepted:
          </div>
          <img src='" . base_path() . \Drupal::service('extension.list.module')->getPath('govuk_pay_webform') . '/images/payments.jpg' . "'/>
        </div>
      ", ['tidy' => FALSE]);
    }

    // Must set wrapper id attribute since we are no longer including #markup.
    // @see template_preprocess_form_element()
    if (isset($element['#theme_wrappers']) && !empty($element['#id'])) {
      $element['#wrapper_attributes']['id'] = $element['#id'];
    }

    // Sent #name property which is used by form-item-* classes.
    if (!isset($element['#name']) && isset($element['#webform_key'])) {
      $element['#name'] = $element['#webform_key'];
    }

    return $element;
  }

}
