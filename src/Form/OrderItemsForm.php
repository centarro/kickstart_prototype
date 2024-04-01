<?php

namespace Drupal\kickstart_prototype\Form;

use Drupal\commerce\Context;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for managing order items en masse on an order.
 */
class OrderItemsForm extends FormBase {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * The currency formatter.
   *
   * @var \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  protected $currencyFormatter;

  /**
   * The chain base price resolver.
   *
   * @var \Drupal\commerce_price\Resolver\ChainPriceResolverInterface
   */
  protected $chainPriceResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentRouteMatch = $container->get('current_route_match');
    $instance->currencyFormatter = $container->get('commerce_price.currency_formatter');
    $instance->chainPriceResolver = $container->get('commerce_price.chain_price_resolver');

    $instance->messenger = $container->get('messenger');
    $instance->order = $instance->currentRouteMatch->getParameter('commerce_order');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'kickstart_prototype_order_items';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['products'] = [
      '#tree' => TRUE,
      '#type' => 'container',
      '#attributes' => [
        'id' => 'kickstart-prototype-order-items-wrapper',
      ],
      '#attached' => [
        'library' => ['kickstart_prototype/order_items'],
      ],
    ];

    // Fetch the current values from the state to prepare summary values.
    $values = $form_state->cleanValues()->getValues();
    $default_values = [];

    if (!empty($values['products'])) {
      foreach ($values['products'] as $product_id => $product_values) {
        if (!empty($product_values['product']['quantity'])) {
          foreach ($product_values['product']['quantity'] as $product_variation_id => $quantity) {
            if (!empty($quantity)) {
              $default_values[$product_id]['total_quantity'] = isset($default_values[$product_id]['total_quantity']) ? $default_values[$product_id]['total_quantity'] + $quantity : $quantity;
            }
          }
        }
      }
    }
    else {
      foreach ($this->order->getItems() as $order_item) {
        $variation = $order_item->getPurchasedEntity();
        $product_id = $variation->getProductId();
        $quantity = round($order_item->getQuantity());
        $default_values[$product_id]['quantity'][$variation->id()] = $quantity;
        $default_values[$product_id]['total_quantity'] = isset($default_values[$product_id]['total_quantity']) ? $default_values[$product_id]['total_quantity'] + $quantity : $quantity;

        if (!isset($default_values[$product_id]['total_price'])) {
          $default_values[$product_id]['total_price'] = new Price(0, 'USD');
        }
        $default_values[$product_id]['total_price'] = $default_values[$product_id]['total_price']->add($variation->getPrice()->multiply($quantity));
      }

      foreach ($default_values as $product_id => $product_values) {
        $default_values[$product_id]['total_price'] = $this->currencyFormatter->format($default_values[$product_id]['total_price']->getNumber(), $default_values[$product_id]['total_price']->getCurrencyCode());
      }
    }

    // $this->messenger->addMessage('<pre>'. print_r($default_values, TRUE) .'</pre>');

    // Load all of the products for now.
    $products = $this->entityTypeManager->getStorage('commerce_product')->loadMultiple();

    foreach ($products as $product) {
      $elements = [];
      $elements['image'] = $product->get('images')[0]->view($this->getImageOptions());

      $elements['product'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['product-row'],
        ],
      ];
      $elements['product']['title'] = [
        '#type' => 'markup',
        '#markup' => '<h4>' . Html::escape($product->getTitle()) . '</h4>',
      ];
      $elements['product']['total_quantity'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Qty'),
        '#default_value' => $default_values[$product->id()]['total_quantity'] ?? '',
        '#size' => 10,
        '#disabled' => TRUE,
        '#weight' => 1,
      ];

      $elements['product']['quantity']['#weight'] = 5;

      // Prepare the total price for this line.
      $total_price = new Price(0, 'USD');

      foreach ($product->getVariations() as $variation) {
        $elements['product']['quantity'][$variation->id()] = [
          '#type' => 'textfield',
          '#title' => Html::escape($variation->getSku()),
          '#default_value' => $default_values[$product->id()]['quantity'][$variation->id()] ?? '',
          '#size' => 18,
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'kickstart-prototype-order-items-wrapper',
            'event' => 'change',
            'disable-refocus' => TRUE,
          ],
        ];

        if (!empty($values['products'][$product->id()]['product']['quantity'][$variation->id()])) {
          $quantity = $values['products'][$product->id()]['product']['quantity'][$variation->id()];
          $total_price = $total_price->add($variation->getPrice()->multiply($quantity));
        }
      }

      $elements['product']['total_price'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Price'),
        '#default_value' => $default_values[$product->id()]['total_price'] ?? '',
        '#size' => 10,
        '#disabled' => TRUE,
        '#weight' => 3,
      ];

      if (!$total_price->isZero()) {
        $elements['product']['total_price']['#value'] = $this->currencyFormatter->format($total_price->getNumber(), $total_price->getCurrencyCode());
        $elements['product']['total_price']['#default_value'] = $this->currencyFormatter->format($total_price->getNumber(), $total_price->getCurrencyCode());
      }

      $form['products'][$product->id()] = [
        '#type' => 'fieldset',
        '#title' => Html::escape($product->getTitle()),
      ] + $elements;
    }

    $form['products']['actions']['#type'] = 'actions';
    $form['products']['actions']['update'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
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
    $values = $form_state->cleanValues()->getValues();
    // $this->messenger->addMessage('<pre>'. print_r($values, TRUE) .'</pre>');

    // First remove every item on the order already.
    foreach ($this->order->getItems() as $order_item) {
      $this->order->removeItem($order_item);
    }


    /** @var \Drupal\commerce_product\ProductVariationStorageInterface $product_variation_storage */
    $product_variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');

    /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');

    // Then add items to match the form submission.
    if (!empty($values['products'])) {
      foreach ($values['products'] as $product_id => $product_values) {
        if (!empty($product_values['product']['quantity'])) {
          foreach ($product_values['product']['quantity'] as $variation_id => $quantity) {
            if (!empty($quantity)) {
              // Prepare the order item.
              $variation = $product_variation_storage->load($variation_id);
              $order_item = $order_item_storage->createFromPurchasableEntity($variation);
              $order_item->setQuantity($quantity);
              $this->prepareOrderItem($order_item);

              // Save it and add it to the cart.
              $order_item->save();
              $this->order->addItem($order_item);
            }
          }
        }
      }
    }

    $this->order->save();

    $this->messenger->addMessage($this->t('Order updated.'));
  }

  /**
   * #ajax callback: Rebuilds the entire form for simplicity's sake.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Returns an options array for image rendering in the form.
   */
  public function getImageOptions() {
    return [
      'type' => 'image_delta_formatter',
      'label' => 'hidden',
      'settings' => [
        'deltas' => '0',
        'image_style' => 'product_teaser',
        'image_link' => '',
        'deltas_reversed' => 0,
      ],
    ];
  }

  /**
   * Helper function used to prepare an order item to be added to an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item to prepare.
   */
  protected function prepareOrderItem(OrderItemInterface $order_item) {
    // Now that the purchased entity is set, populate the title and price.
    $purchased_entity = $order_item->getPurchasedEntity();
    $order_item->setTitle($purchased_entity->getOrderItemTitle());

    if (!$order_item->isUnitPriceOverridden()) {
      $context = new Context($this->order->getCustomer(), $this->order->getStore());
      $resolved_price = $this->chainPriceResolver->resolve($purchased_entity, $order_item->getQuantity(), $context);
      $order_item->setUnitPrice($resolved_price);
    }

    $order_item->set('order_id', $this->order->id());
  }
}
