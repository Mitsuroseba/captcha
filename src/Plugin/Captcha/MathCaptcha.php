<?php

/**
 * @file
 * Contains Drupal\captcha\Plugin\Captcha\MathCaptcha.
 */

namespace Drupal\captcha\Plugin\Captcha;

use Drupal\captcha\Plugin\CaptchaInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * MathCaptcha class.
 *
 * @Captcha(
 *   id = "math",
 *   title = @Translation("Math Captcha")
 * )
 */
class MathCaptcha extends PluginBase implements CaptchaInterface {

  /**
   * First addendum for math expression.
   *
   * @var int
   */
  protected $firstAddendum;

  /**
   * Second addendum for math expression.
   *
   * @var int
   */
  protected $secondAddendum;

  /**
   * Diff of first and second addendums.
   *
   * @var int
   */
  protected $solution;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->firstAddendum = mt_rand(1, 20);
    $this->solution = mt_rand(1, $this->firstAddendum);
    $this->secondAddendum = $this->solution + $this->firstAddendum;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestionFormElement(array $form, FormStateInterface $form_state, $captcha_sid) {
    return array(
      '#markup' => $this->firstAddendum . ' + ' . $this->secondAddendum
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerFormElement(array $form, FormStateInterface $form_state) {
    return array(
      '#type' => 'textfield',
      '#title' => t('Math question'),
      '#description' => t('Solve this simple math problem and enter the result. E.g. for 1+3, enter 4.'),
      '#field_prefix' => t('@x + @y = ', array('@x' => $this->firstAddendum, '@y' => $this->secondAddendum)),
      '#size' => 4,
      '#maxlength' => 2,
      '#required' => TRUE,
      '#attributes' => array(
        'autocomplete' => 'off',
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getChallengeDescription() {
    return $this->t('Solve this simple math problem and enter the result. E.g. for 1+3, enter 4.');
  }

  public function getQuestionDescription() {
    return $this->t('Math');
  }

  /**
   * @todo place docs here.
   */
  public function getSolutionValue($captcha_sid) {
    return $this->solution;
  }

}
