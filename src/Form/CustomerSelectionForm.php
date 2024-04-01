<?php

namespace Drupal\kickstart_prototype\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for indexing, clearing, etc., an index.
 */
class CustomerSelectionForm extends FormBase {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a CustomerSelectionForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $messenger = $container->get('messenger');

    return new static($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'kickstart_prototype_customer_selection';
  }

  /**
   * Returns the list of customers the user can see in the selection widget.
   */
  public function getCustomers() {
    return [
      2 => 'Ryan Szrama',
      3 => 'Jonathan Sacksick',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $widget_type = 'select') {
    $session = \Drupal::request()->getSession();

    $form['customer_uid'] = [
      '#type' => $widget_type,
      '#title' => $this->t('Customer'),
      '#options' => [
        0 => $this->t('- None -'),
      ] + $this->getCustomers(),
      '#default_value' => $session->get('kickstart_prototype.customer_uid'),
    ];

    // Add actions for reindexing and for clearing the index.
    $form['actions']['#type'] = 'actions';
    $form['actions']['update'] = [
      '#type' => 'submit',
      '#value' => $this->t('Switch'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $customer_uid = $form_state->getValue('customer_uid');
    $session = \Drupal::request()->getSession();

    if (!empty($customer_uid)) {
      $customers = $this->getCustomers();

      $session->set('kickstart_prototype.customer_uid', $customer_uid);
      $this->messenger->addMessage($this->t('Switching to %customer.', ['%customer' => $customers[$customer_uid]]));
    }
    else {
      $session->remove('kickstart_prototype.customer_uid');
      $this->messenger->addMessage($this->t('Reverted to your own account.'));
    }
  }
}
