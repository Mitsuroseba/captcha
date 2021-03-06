<?php

/**
 * @file
 * Contains \Drupal\captcha\Render\Element\CaptchaFormElement.
 */

namespace Drupal\captcha\Render\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;

/**
 * Provides a complex captcha form element.
 *
 * @package Drupal\captcha\Render\Element
 *
 * @FormElement('captcha')
 */
class CaptchaFormElement extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    // Override the default CAPTCHA validation function for case
    // insensitive validation.
    // TODO: shouldn't this be done somewhere else, e.g. in form_alter?
    if (CAPTCHA_DEFAULT_VALIDATION_CASE_INSENSITIVE == \Drupal::config('captcha.settings')->get('captcha_default_validation')) {
      $captcha_element['#captcha_validate'] = 'captcha_validate_case_insensitive_equality';
    }

    return array(
      '#default_value' => NULL,
      '#required' => TRUE,
      '#name' => 'op',
      '#executes_submit_callback' => FALSE,
      '#limit_validation_errors' => FALSE,
      '#process' => array(
        array($class, 'process'),
      ),
      '#element_validate' => array(
        // @todo Move all validation callbacks to plugins.
        array('captcha_validate'),
      ),
      // Implements _drupal_render_process_post_render_cache() for captcha elements.
      // '#post_render' => array(
      //  array($class, 'postRenderButton'),
      // ),
      '#theme' => 'captcha',
    );
  }

  public function process(array $element, FormStateInterface $form_state, array $complete_form) {
    // Add captcha.inc file
    module_load_include('inc', 'captcha');

    // Add Javascript for general CAPTCHA functionality.
    $element['#attached']['library'][] = 'captcha/base';
    // Prevent caching of the page with CAPTCHA elements.
    // This needs to be done even if the CAPTCHA will be ommitted later:
    // other untrusted users should not get a cached page when
    // the current untrusted user can skip the current CAPTCHA.
    global $conf;
    $conf['cache'] = FALSE;

    // Get the form ID of the form we are currently processing (which is not
    // necessary the same form that is submitted (if any).
    $this_form_id = $complete_form['form_id']['#value'];

    // Get the CAPTCHA session ID.
    // If there is a submitted form: try to retrieve and reuse the
    // CAPTCHA session ID from the posted data.
    list($posted_form_id, $posted_captcha_sid) = _captcha_get_posted_captcha_info($element, $form_state, $this_form_id);
    if ($this_form_id == $posted_form_id && isset($posted_captcha_sid)) {
      $captcha_sid = $posted_captcha_sid;
    }
    else {
      // Generate a new CAPTCHA session if we could not reuse one from a posted form.
      $captcha_sid = _captcha_generate_captcha_session($this_form_id, CAPTCHA_STATUS_UNSOLVED);
    }

    // Store CAPTCHA session ID as hidden field.
    // Strictly speaking, it is not necessary to send the CAPTCHA session id
    // with the form, as the one time CAPTCHA token (see lower) is enough.
    // However, we still send it along, because it can help debugging
    // problems on live sites with only access to the markup.
    $element['captcha_sid'] = array(
      '#type' => 'hidden',
      '#value' => $captcha_sid,
    );

    // Additional one time CAPTCHA token: store in database and send with form.
    $captcha_token = md5(mt_rand());
    db_update('captcha_sessions')
      ->fields(array('token' => $captcha_token))
      ->condition('csid', $captcha_sid)
      ->execute();
    $element['captcha_token'] = array(
      '#type' => 'hidden',
      '#value' => $captcha_token,
    );

    // Get implementing module and challenge for CAPTCHA.
    list($captcha_type_module, $captcha_type_challenge) = _captcha_parse_captcha_type($element['#captcha_type']);

    // Store CAPTCHA information for further processing in
    // - $form_state['captcha_info'], which survives a form rebuild (e.g. node
    //   preview), useful in _captcha_get_posted_captcha_info().
    // - $element['#captcha_info'], for post processing functions that do not
    //   receive a $form_state argument (e.g. the pre_render callback).
    $form_state->set('captcha_info', array(
      'this_form_id' => $this_form_id,
      'posted_form_id' => $posted_form_id,
      'captcha_sid' => $captcha_sid,
      'module' => $captcha_type_module,
      'captcha_type' => $captcha_type_challenge,
    ));
    $element['#captcha_info'] = array(
      'form_id' => $this_form_id,
      'captcha_sid' => $captcha_sid,
    );

    if (_captcha_required_for_user($captcha_sid, $this_form_id) || $element['#captcha_admin_mode']) {
      // Generate a CAPTCHA and its solution
      // (note that the CAPTCHA session ID is given as third argument).
      $captcha = \Drupal::moduleHandler()->invoke($captcha_type_module, 'captcha', array('generate', $captcha_type_challenge, $captcha_sid));
      if (!isset($captcha['form']) || !isset($captcha['solution'])) {
        // The selected module did not return what we expected: log about it and quit.
        \Drupal::logger('CAPTCHA')->error(
          'CAPTCHA problem: unexpected result from hook_captcha() of module %module when trying to retrieve challenge type %type for form %form_id.',
          array('%type' => $captcha_type_challenge, '%module' => $captcha_type_module, '%form_id' => $this_form_id)
        );

        return $element;
      }
      // Add form elements from challenge as children to CAPTCHA form element.
      $element['captcha_widgets'] = $captcha['form'];

      // Add a validation callback for the CAPTCHA form element
      // (when not in admin mode).
      if (!$element['#captcha_admin_mode']) {
        $element['#element_validate'] = array('captcha_validate');
      }

      // Set a custom CAPTCHA validate function if requested.
      if (isset($captcha['captcha_validate'])) {
        $element['#captcha_validate'] = $captcha['captcha_validate'];
      }

      // Set the theme function.
      $element['#theme'] = 'captcha';

      // Add pre_render callback for additional CAPTCHA processing.
      if (!isset($element['#pre_render'])) {
        $element['#pre_render'] = array();
      }
      $element['#pre_render'][] = 'captcha_pre_render_process';

      // Store the solution in the #captcha_info array.
      $element['#captcha_info']['solution'] = $captcha['solution'];

      // Make sure we can use a top level form value
      // $form_state['values']['captcha_response'], even if the form has #tree=true.
      $element['#tree'] = FALSE;
    }

    return $element;
  }

}